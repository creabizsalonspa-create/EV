"""Test explicit cross-PR dependencies independently of Github's branch graph."""
import importlib.util
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "ev_integration_dependency_guards", ROOT / "scripts/integration_readiness.py"
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
SHA = "a" * 40
MERGE = "b" * 40
TARGET_SHA = "c" * 40


def pr(n=70, head=SHA, base=gate.TARGET, state="open", merged_at=None,
       merge_commit_sha=None):
    return {
        "number": n, "title": "Synthetic cross-PR workflow",
        "head": {"ref": f"development/test-{n}", "sha": head},
        "base": {"ref": base}, "state": state, "draft": False,
        "mergeable": True, "merged_at": merged_at,
        "merge_commit_sha": merge_commit_sha,
    }


def evidence(n=70):
    return [{
        "name": name, "status": "completed", "conclusion": "success",
        "head_sha": SHA, "pull_requests": [{"number": n}],
        "details_url": "https://github.com/creabizsalonspa-create/EV/"
                       "actions/runs/54321/job/98765",
    } for name in sorted(gate.CORE)]


class DependenciesTest(unittest.TestCase):
    def test_source_backed_manifest_has_no_cycles(self):
        graph = gate.load_pr_dependencies()
        self.assertEqual((50, 52), graph[70])
        self.assertEqual((63,), graph[69])
        self.assertEqual((61,), graph[63])
        self.assertEqual((59,), graph[61])
        self.assertEqual((53,), graph[56])
        self.assertEqual((57,), graph[67])

    def test_reject_malformed_missing_wrong_target_or_cycle(self):
        initial = {"schema_version": 1, "target_branch": gate.TARGET,
                   "dependencies": {"70": [50, 52]}}
        modifications = [
            {"schema_version": 2},
            {"target_branch": "development/not-integration"},
            {"dependencies": {"70": [70]}},
            {"dependencies": {"70": [50], "50": [70]}},
            {"dependencies": {"70": [50, 50]}},
            {"dependencies": {"70": [True]}},
            {"dependencies": {"070": [50]}},
            {"dependencies": {"70": []}},
            {"dependencies": {"70": ["50"]}},
            {"dependencies": {"70": [0]}},
        ]
        for patch_data in modifications:
            with self.subTest(change=patch_data):
                broken = {**initial, **patch_data}
                with TemporaryDirectory() as temp:
                    path = Path(temp) / "graph.json"
                    path.write_text(json.dumps(broken))
                    with self.assertRaises(ValueError):
                        gate.load_pr_dependencies(path)

    def test_open_prs_are_not_integrated_regardless_of_green_checks(self):
        result = gate.dependency_status(
            object(), 50, gate.TARGET, TARGET_SHA, {50: pr(50)})
        self.assertEqual("pr_still_open", result["reason"])
        self.assertFalse(result["integrated"])

    def test_wrong_merge_target_cannot_satisfy_dependency(self):
        class Github:
            def get(self, suffix):
                self_path = "/pulls/50"
                self_is_wrong = suffix == self_path
                if self_is_wrong:
                    return pr(50, base="development/universal-integration",
                              merged_at="2026-11-07T12:00:00Z",
                              merge_commit_sha=MERGE)
                raise AssertionError(suffix)
        result = gate.dependency_status(Github(), 50, gate.TARGET,
                                        TARGET_SHA, {})
        self.assertEqual("not_merged_into_integration_target", result["reason"])

    def test_a_closed_but_unmerged_pr_is_not_integrated(self):
        class Github:
            def get(self, suffix):
                self_should_be_pr = suffix == "/pulls/50"
                if self_should_be_pr:
                    return pr(50, state="closed")
                raise AssertionError(suffix)
        result = gate.dependency_status(Github(), 50, gate.TARGET,
                                        TARGET_SHA, {})
        self.assertFalse(result["integrated"])

    def test_comparison_must_show_actual_integration_ancestry(self):
        class Github:
            def __init__(self, status):
                self.status = status
            def get(self, suffix):
                if suffix == "/pulls/50":
                    return pr(50, state="closed", merged_at="2026-11-07T12:00:00Z",
                              merge_commit_sha=MERGE)
                if suffix == f"/compare/{MERGE}...{TARGET_SHA}":
                    return {"status": self.status}
                raise AssertionError(suffix)
        for state in ("behind", "diverged", "unknown"):
            with self.subTest(status=state):
                value = gate.dependency_status(Github(state), 50, gate.TARGET,
                                               TARGET_SHA, {})
                self.assertFalse(value["integrated"])
                self.assertEqual("merge_not_in_integration_history",
                                 value["reason"])
        for state in ("ahead", "identical"):
            with self.subTest(status=state):
                value = gate.dependency_status(Github(state), 50, gate.TARGET,
                                               TARGET_SHA, {})
                self.assertTrue(value["integrated"])

    def test_declared_prs_fail_closed_if_dependency_checks_missing(self):
        run = gate.assess(
            pr(), evidence(), ["README.md"], pr_event_run_ids={54321},
            prerequisites=(50, 52),
        )
        self.assertEqual("blocked", run["status"])
        self.assertIn("missing_pr_dependency_verification", run["blockers"])
        self.assertIn("unintegrated_pr_dependencies:50,52", run["blockers"])

    def test_only_attested_source_dependencies_permit_review_candidate(self):
        states = {
            50: {"number": 50, "integrated": True,
                 "reason": "verified_merged_ancestor"},
            52: {"number": 52, "integrated": True,
                 "reason": "verified_merged_ancestor"},
        }
        allowed = gate.assess(
            pr(), evidence(), ["README.md"], pr_event_run_ids={54321},
            prerequisites=(50, 52), prerequisite_status=states)
        self.assertEqual("candidate_for_manual_review", allowed["status"])
        self.assertEqual([50, 52], allowed["prerequisites"])
        self.assertFalse(allowed["merge_executed"])
        states[52] = {"number": 52, "integrated": False,
                      "reason": "pr_still_open"}
        blocked = gate.assess(
            pr(), evidence(), ["README.md"], pr_event_run_ids={54321},
            prerequisites=(50, 52), prerequisite_status=states)
        self.assertEqual("blocked", blocked["status"])
        self.assertIn("unintegrated_pr_dependencies:52", blocked["blockers"])

    def test_targeted_audit_handles_more_than_thirty_active_prs(self):
        # Ten agents routinely produce 30+ drafts. Targeted audits must still
        # inspect a selected PR and its separate API dependency edges.
        active = [pr(n) for n in range(1, 34)] + [pr(50), pr(52)]

        class FakeGitHub:
            def __init__(self, *args, **kwargs):
                pass
            def get(self, suffix):
                if suffix == "/pulls/70":
                    return pr(70)
                raise AssertionError(suffix)
            def pages(self, suffix, key=None):
                if suffix == "/pulls?state=open":
                    return active
                if suffix == "/pulls/70/files":
                    return [{"filename": "README.md"}]
                if suffix.startswith("/commits/"):
                    return evidence(70)
                if suffix.startswith("/actions/runs?"):
                    return [{
                        "id": 54321, "event": "pull_request",
                        "head_sha": SHA, "pull_requests": [{"number": 70}],
                    }]
                raise AssertionError(suffix)
        with patch.object(gate, "GitHub", FakeGitHub):
            report = gate.inspect_live(only_pr=70)
        self.assertEqual(1, report["audited_prs"])
        self.assertEqual("blocked", report["prs"][0]["status"])
        self.assertEqual([50, 52], report["prs"][0]["prerequisites"])
        self.assertIn("unintegrated_pr_dependencies:50,52",
                      report["prs"][0]["blockers"])
        self.assertEqual(7, report["declared_dependency_edges"])


if __name__ == "__main__":
    unittest.main()
