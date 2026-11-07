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
                                  "sha": SHA_A, "event": "push", "branch": BRANCH}]}
        class Fake:
            token, repo = "synthetic", REPO
            posted = False
            def pages(self, suffix, key):
                if suffix.startswith("/pulls?"):
                    return [pr()], False
                raise AssertionError(suffix)
            def queued_pages(self, limit=triage.MAX_PAGE_COUNT):
                return [r], False
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
                                  "sha": SHA_A, "event": "push", "branch": BRANCH}]}
        class Fake:
            token, repo = "synthetic", REPO
            posted = False
            def pages(self, suffix, key):
                if suffix.startswith("/pulls?"):
                    return [pr(sha=SHA_A)], False
                raise AssertionError(suffix)
            def queued_pages(self, limit=triage.MAX_PAGE_COUNT):
                return [r], False
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
             "sha": SHA_A, "event": "push", "branch": BRANCH},
            {"id": 2, "reason": "superseded_feature_push",
             "sha": SHA_A, "event": "push", "branch": BRANCH},
        ]}
        class Fake:
            token, repo = "synthetic", REPO
            def pages(self, suffix, key):
                if suffix.startswith("/pulls?"):
                    return [pr()], False
                raise AssertionError(suffix)
            def queued_pages(self, limit=triage.MAX_PAGE_COUNT):
                return [r1, r2], False
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


    def test_queue_scan_can_page_past_twelve_hundred_queued_runs(self):
        self.assertGreaterEqual(triage.MAX_PAGE_COUNT, 30)
        github = triage.Github()
        def fake_request(suffix):
            page = int(suffix.rsplit("=", 1)[-1])
            if page <= 12:
                return {"workflow_runs": [
                    run(id=page * 100 + offset) for offset in range(100)
                ]}
            if page == 13:
                return {"workflow_runs": [run(id=1301)]}
            raise AssertionError("Full queue should end after 13 pages")
        github.request = fake_request
        found, incomplete = github.pages(
            "/actions/runs?status=queued", "workflow_runs")
        self.assertEqual(1201, len(found))
        self.assertFalse(incomplete)

    def test_manual_workflow_scan_limit_tracks_safe_runtime_limit(self):
        workflow = (
            ROOT / ".github/workflows/queued-ci-maintenance.yml"
        ).read_text()
        self.assertEqual(2, workflow.count("--page-limit 30"))
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("actions: write", workflow)
        self.assertIn("needs: dry-run", workflow)


    def test_obsolete_fork_pr_run_is_never_cancelled(self):
        pending = run(event="pull_request", prs=[70], sha=SHA_A)
        external = pr(repo="untrusted-fork/EV", sha=SHA_B)
        self.assertIsNone(classify([pending], active=[external]))

    def test_missing_run_repository_provenance_fails_closed(self):
        pending = run()
        del pending["repository"]
        self.assertIsNone(classify([pending]))


    def test_actions_api_1000_result_ceiling_is_reported_incomplete(self):
        github = triage.Github()
        def fake_request(suffix):
            page = int(suffix.rsplit("=", 1)[-1])
            if page <= 10:
                return {"total_count": 1360, "workflow_runs": [
                    run(id=page * 100 + offset) for offset in range(100)
                ]}
            # GitHub sometimes returns an empty page beyond its hard cap,
            # despite reporting >1,000 queued runs on earlier pages.
            return {"total_count": 0, "workflow_runs": []}
        github.request = fake_request
        rows, incomplete = github.pages(
            "/actions/runs?status=queued", "workflow_runs")
        self.assertEqual(1000, len(rows))
        self.assertTrue(incomplete,
                        "Partial GitHub scan must not authorize cancellation")
        github.token = "synthetic-not-used"
        with self.assertRaises(ValueError):
            triage.cancel_candidates(
                github, {"queue_scan_truncated": incomplete,
                         "candidates": []}, 1)

    def test_actions_reported_total_may_not_be_smaller_than_returned_page(self):
        github = triage.Github()
        github.request = lambda suffix: {
            "total_count": 2, "workflow_runs": [run(id=1)]
        }
        rows, incomplete = github.pages(
            "/actions/runs?status=queued", "workflow_runs")
        self.assertEqual(1, len(rows))
        self.assertTrue(incomplete)

    def test_plain_pr_list_still_pages_without_total_count(self):
        github = triage.Github()
        github.request = lambda suffix: [pr()]
        prs, incomplete = github.pages("/pulls?state=open", "pull_requests")
        self.assertEqual(1, len(prs))
        self.assertFalse(incomplete)


    def test_queue_split_recovers_runs_hidden_beyond_github_cap(self):
        github = triage.Github()
        # The API exposes 1,360 queued runs, but unfiltered pagination returns
        # only the newest 1,000; created-range pagination exposes the older 361,
        # including one duplicated run at the timestamp boundary.
        newest = [dict(run(id=i), created_at="2026-11-07T14:00:00Z")
                  for i in range(1360, 360, -1)]
        newest[-1]["created_at"] = "2026-11-07T13:00:00Z"
        older = [dict(run(id=i), created_at="2026-11-07T12:00:00Z")
                 for i in range(360, 0, -1)] + [newest[-1]]
        older.sort(key=lambda x: x["created_at"], reverse=True)

        def response(suffix):
            from urllib.parse import parse_qs
            query = parse_qs(suffix.split("?", 1)[-1])
            page = int(query.get("page", ["1"])[0])
            per_page = int(query.get("per_page", ["100"])[0])
            if "created" in query:
                self.assertIn("..2026-11-07T13:00:00Z",
                              query["created"][0])
                rows = older
                total = len(older)
            else:
                rows = newest
                total = 1360 if per_page == 1 or page <= 10 else 0
            start = (page - 1) * per_page
            return {"total_count": total,
                    "workflow_runs": rows[start:start + per_page]}
        github.request = response
        combined, incomplete = github.queued_pages()
        self.assertFalse(incomplete)
        self.assertEqual(1360, len(combined))
        self.assertEqual(1360, len({r["id"] for r in combined}))

    def test_queue_split_refuses_new_work_arriving_during_scan(self):
        github = triage.Github()
        newest = [dict(run(id=i), created_at="2026-11-07T14:00:00Z")
                  for i in range(1360, 360, -1)]
        newest[-1]["created_at"] = "2026-11-07T13:00:00Z"
        older = [dict(run(id=i), created_at="2026-11-07T12:00:00Z")
                 for i in range(360, 0, -1)] + [newest[-1]]
        older.sort(key=lambda x: x["created_at"], reverse=True)
        def response(suffix):
            from urllib.parse import parse_qs
            query = parse_qs(suffix.split("?", 1)[-1])
            page = int(query.get("page", ["1"])[0])
            per_page = int(query.get("per_page", ["100"])[0])
            if "created" in query:
                rows, total = older, len(older)
            else:
                rows = newest
                total = (1361 if per_page == 1 else
                         1360 if page <= 10 else 0)
            start = (page - 1) * per_page
            return {"total_count": total,
                    "workflow_runs": rows[start:start + per_page]}
        github.request = response
        combined, incomplete = github.queued_pages()
        self.assertTrue(incomplete)
        self.assertEqual(1360, len(combined))

    def test_queue_split_rejects_missing_timestamps(self):
        github = triage.Github()
        def response(suffix):
            from urllib.parse import parse_qs
            query = parse_qs(suffix.split("?", 1)[-1])
            page = int(query.get("page", ["1"])[0])
            return {"total_count": 1300 if page <= 10 else 0,
                    "workflow_runs": [
                        run(id=page * 100 + index)
                        for index in range(100 if page <= 10 else 0)
                    ]}
        github.request = response
        rows, incomplete = github.queued_pages()
        self.assertTrue(incomplete)
        self.assertEqual(1000, len(rows))


    def test_operator_cancellation_never_rescans_all_queued_runs(self):
        target = run(id=4)
        report = {"queue_scan_truncated": False, "candidates": [{
            "id": 4, "reason": "superseded_feature_push",
            "sha": SHA_A, "event": "push", "branch": BRANCH,
        }]}
        class Fake:
            token, repo = "synthetic", REPO
            posts = []
            def pages(self, suffix, key):
                if not suffix.startswith("/pulls?"):
                    raise AssertionError("Must not download the entire queue per run")
                return [pr()], False
            def queued_pages(self):
                raise AssertionError("Per-run rescan is prohibitively expensive")
            def request(self, suffix, method="GET"):
                if method == "GET":
                    self.assert_equal = True
                    return target
                self.posts.append(suffix)
                return 202
        api = Fake()
        done, skipped, errors = triage.cancel_candidates(api, report, 1)
        self.assertEqual([4], done)
        self.assertEqual([], skipped)
        self.assertEqual([], errors)
        self.assertEqual(["/actions/runs/4/cancel"], api.posts)

    def test_current_head_push_duplicate_is_never_cancelled_automatically(self):
        report = {"queue_scan_truncated": False, "candidates": [{
            "id": 4, "reason": "duplicate_feature_push_with_pr_event",
            "sha": SHA_B, "event": "push", "branch": BRANCH,
        }]}
        class Fake:
            token, repo = "synthetic", REPO
            def pages(self, *args):
                raise AssertionError("Duplicate push must be kept")
            def request(self, *args, **kwargs):
                raise AssertionError("Duplicate push must be kept")
        done, skipped, errors = triage.cancel_candidates(Fake(), report, 1)
        self.assertEqual([], done)
        self.assertEqual([4], skipped)
        self.assertEqual([], errors)

    def test_run_is_never_cancelled_after_branch_change(self):
        run_before = run(id=4)
        moved = dict(run_before, head_branch="development/moved")
        report = {"queue_scan_truncated": False, "candidates": [{
            "id": 4, "reason": "superseded_feature_push",
            "sha": SHA_A, "event": "push", "branch": BRANCH,
        }]}
        class Fake:
            token, repo = "synthetic", REPO
            read_count = 0
            def pages(self, suffix, key):
                return [pr()], False
            def request(self, suffix, method="GET"):
                if method != "GET":
                    raise AssertionError("Stale run must not be cancelled")
                self.read_count += 1
                return run_before if self.read_count == 1 else moved
        done, skipped, errors = triage.cancel_candidates(Fake(), report, 1)
        self.assertEqual([], done)
        self.assertEqual([4], skipped)
        self.assertEqual([], errors)


if __name__ == "__main__":
    unittest.main()
