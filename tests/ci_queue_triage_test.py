"""Fail-closed cancellation guard tests. Never make a network request in CI."""
import importlib.util
from pathlib import Path
import unittest
from contextlib import redirect_stdout
from io import StringIO
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "ev_ci_queue_triage", ROOT / "scripts/ci_queue_triage.py"
)
triage = importlib.util.module_from_spec(spec)
spec.loader.exec_module(triage)

SHA_A, SHA_B = "a" * 40, "b" * 40
BRANCH = "development/agent9-regression-20261107"
REPO = triage.REPO


def pr(n=70, ref=BRANCH, sha=SHA_B, repo=REPO):
    return {"number": n, "state": "open",
            "head": {"ref": ref, "sha": sha,
                     "repo": {"full_name": repo}}}


def run(id=1, event="push", sha=SHA_A, branch=BRANCH,
        status="queued", workflow=345, prs=None):
    return {"id": id, "event": event, "head_sha": sha,
            "head_branch": branch, "status": status,
            "workflow_id": workflow,
            "repository": {"full_name": REPO},
            "pull_requests": [{"number": n} for n in (prs or [])],
            "name": "Platform validation"}


def classify(rows, active=None, selected=None):
    heads, numbers, paired = triage.build_context(
        active if active is not None else [pr()], rows)
    target = selected or rows[0]
    return triage.classify(target, heads, numbers, paired)


class QueueTriageTest(unittest.TestCase):
    def test_obsolete_pr_run_may_be_released(self):
        r = run(event="pull_request", prs=[70])
        self.assertEqual("superseded_pr_head", classify([r]))

    def test_latest_pr_head_is_never_cancelled(self):
        r = run(event="pull_request", sha=SHA_B, prs=[70])
        self.assertIsNone(classify([r]))

    def test_obsolete_feature_push_can_be_released(self):
        r = run()
        self.assertEqual("superseded_feature_push", classify([r]))

    def test_current_push_is_preserved_without_same_workflow_pr_validation(self):
        r = run(sha=SHA_B)
        self.assertIsNone(classify([r]))
        different = run(2, "pull_request", SHA_B, workflow=987, prs=[70])
        self.assertIsNone(classify([r, different]))

    def test_current_push_requires_same_sha_pr_and_workflow_before_dedup(self):
        push = run(sha=SHA_B)
        equivalent = run(2, "pull_request", SHA_B, prs=[70])
        self.assertEqual("duplicate_feature_push_with_pr_event",
                         classify([push, equivalent]))
        self.assertIsNone(classify([push, equivalent], selected=equivalent))

    def test_other_pr_event_cannot_replace_own_pr_validation(self):
        push = run(sha=SHA_B)
        other = run(2, "pull_request", SHA_B, prs=[71])
        self.assertIsNone(classify([push, other]))

    def test_inprogress_completed_and_dispatch_are_always_protected(self):
        for status in ("in_progress", "completed", "pending"):
            with self.subTest(status=status):
                self.assertIsNone(classify([run(status=status)]))
        self.assertIsNone(classify([run(event="workflow_dispatch")]))

    def test_authoritative_release_and_staging_branches_are_protected(self):
        for branch in (
            "development/fullstack-continuation",
            "development/platform-foundation", "main", "master",
            "production/uae", "release/2026-11", "staging/preview",
            "feature/single-owner",
        ):
            with self.subTest(branch=branch):
                self.assertIsNone(classify([run(branch=branch)],
                                           active=[pr(ref=branch)]))

    def test_unknown_feature_branch_and_fork_pr_are_protected(self):
        self.assertIsNone(classify([run()], active=[]))
        self.assertIsNone(classify([run()], active=[
            pr(repo="external-fork/EV")]))

    def test_nonmatching_repository_or_invalid_id_is_protected(self):
        r = run()
        r["repository"] = {"full_name": "other/repo"}
        self.assertIsNone(classify([r]))
        r = run(id=True)
        self.assertIsNone(classify([r]))

    def test_multiple_active_prs_one_shared_branch_require_all_checks(self):
        p1, p2 = pr(70), pr(71)
        push = run(sha=SHA_B)
        one = run(2, "pull_request", SHA_B, prs=[70])
        self.assertIsNone(classify([push, one], active=[p1, p2]))
        two = run(3, "pull_request", SHA_B, prs=[71])
        self.assertEqual("duplicate_feature_push_with_pr_event",
                         classify([push, one, two], active=[p1, p2]))

    def test_unrecognised_or_closed_pr_link_cannot_cancel_pr_run(self):
        r = run(event="pull_request", prs=[71])
        self.assertIsNone(classify([r]))
        r = run(event="pull_request", prs=[])
        self.assertIsNone(classify([r]))

    def test_pull_list_is_plain_array_and_workflow_runs_are_object(self):
        g = triage.Github()
        def request(suffix):
            if suffix.startswith("/pulls?"):
                return [pr()]
            if suffix.startswith("/actions/runs?"):
                return {"workflow_runs": [run()]}
            raise AssertionError(suffix)
        g.request = request
        prs, truncated = g.pages("/pulls?state=open", "pull_requests")
        self.assertEqual([pr()], prs)
        self.assertFalse(truncated)
        report = triage.inspect(g)
        self.assertEqual(1, report["candidate_count"])
        self.assertEqual(0, report["cancellations_executed"])

    def test_apply_requires_explicit_credential_and_complete_queue(self):
        g = triage.Github()
        with self.assertRaises(PermissionError):
            triage.cancel_candidates(g, {"queue_scan_truncated": False}, 10)
        g.token = "synthetic-never-used"
        with self.assertRaises(ValueError):
            triage.cancel_candidates(g, {"queue_scan_truncated": True}, 10)
        with self.assertRaises(ValueError):
            triage.cancel_candidates(g, {"queue_scan_truncated": False}, 51)

    def test_final_recheck_blocks_a_run_that_began_executing(self):
        r = run()
        report = {"queue_scan_truncated": False,
                  "candidates": [{"id": 1, "reason": "superseded_feature_push",
                                  "sha": SHA_A, "event": "push"}]}
        class Fake:
            token, repo = "synthetic", REPO
            posted = False
            def pages(self, suffix, key):
                if suffix.startswith("/pulls?"):
                    return [pr()], False
                if suffix.startswith("/actions/runs?"):
                    return [r], False
                raise AssertionError(suffix)
            def request(self, suffix, method="GET"):
                if method != "GET":
                    self.posted = True
                    raise AssertionError("Cancellation must not occur")
                return dict(r, status="in_progress")
        g = Fake()
        cancelled, skipped, errors = triage.cancel_candidates(g, report, 1)
        self.assertEqual([], cancelled)
        self.assertEqual([1], skipped)
        self.assertEqual([], errors)
        self.assertFalse(g.posted)

    def test_operator_apply_refuses_if_branch_head_catches_up(self):
        r = run()
        report = {"queue_scan_truncated": False,
                  "candidates": [{"id": 1, "reason": "superseded_feature_push",
                                  "sha": SHA_A, "event": "push"}]}
        class Fake:
            token, repo = "synthetic", REPO
            posted = False
            def pages(self, suffix, key):
                if suffix.startswith("/pulls?"):
                    return [pr(sha=SHA_A)], False
                if suffix.startswith("/actions/runs?"):
                    return [r], False
                raise AssertionError(suffix)
            def request(self, suffix, method="GET"):
                if method != "GET":
                    self.posted = True
                    raise AssertionError("Unsafe cancellation")
                return r
        g = Fake()
        cancelled, skipped, errors = triage.cancel_candidates(g, report, 1)
        self.assertEqual([], cancelled)
        self.assertEqual([1], skipped)
        self.assertEqual([], errors)
        self.assertFalse(g.posted)


    def test_later_api_failure_preserves_exact_prior_cancelled_ids(self):
        from urllib.error import HTTPError
        r1, r2 = run(1), run(2)
        report = {"queue_scan_truncated": False, "candidates": [
            {"id": 1, "reason": "superseded_feature_push",
             "sha": SHA_A, "event": "push"},
            {"id": 2, "reason": "superseded_feature_push",
             "sha": SHA_A, "event": "push"},
        ]}
        class Fake:
            token, repo = "synthetic", REPO
            def pages(self, suffix, key):
                if suffix.startswith("/pulls?"):
                    return [pr()], False
                if suffix.startswith("/actions/runs?"):
                    return [r1, r2], False
                raise AssertionError(suffix)
            def request(self, suffix, method="GET"):
                if method == "GET":
                    return r1 if suffix.endswith("/1") else r2
                if suffix.endswith("/1/cancel"):
                    return 202
                raise HTTPError("https://example.invalid", 403,
                                "Actions permission missing", {}, None)
        done, skipped, errors = triage.cancel_candidates(Fake(), report, 2)
        self.assertEqual([1], done)
        self.assertEqual([], skipped)
        self.assertEqual([{"id": 2, "type": "HTTPError", "http_status": 403}],
                         errors)



    def test_operator_summary_is_compact_and_never_cancels(self):
        fixture = {
            "repository": REPO, "queued_scanned": 4,
            "queue_scan_truncated": False, "open_pr_count": 2,
            "candidate_count": 3, "cancellations_executed": 0,
            "candidates": [
                {"id": 1, "reason": "superseded_feature_push"},
                {"id": 2, "reason": "superseded_feature_push"},
                {"id": 3, "reason": "superseded_pr_head"},
            ],
        }
        stdout = StringIO()
        with patch.object(triage, "inspect", return_value=fixture), \
                patch.dict("os.environ", {"GH_TOKEN": "", "GITHUB_TOKEN": ""}), \
                redirect_stdout(stdout):
            self.assertEqual(0, triage.main(["--summary-only"]))
        shown = __import__("json").loads(stdout.getvalue())
        self.assertNotIn("candidates", shown)
        self.assertEqual(
            {"superseded_feature_push": 2, "superseded_pr_head": 1},
            shown["candidate_reasons"])
        self.assertEqual(3, shown["candidate_count"])
        self.assertEqual(0, shown["cancellations_executed"])

    def test_manual_apply_refuses_missing_credentials_before_any_lookup(self):
        with patch.dict("os.environ", {"GH_TOKEN": "", "GITHUB_TOKEN": ""}), \
                patch.object(triage, "inspect", side_effect=AssertionError(
                    "Apply without authorization must never call GitHub")):
            self.assertEqual(2, triage.main(["--apply", "--max-cancel", "1"]))


if __name__ == "__main__":
    unittest.main()
