"""Offline gate tests: never interpret a passing partial PR as merge approval."""
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "ev_integration_readiness", ROOT / "scripts/integration_readiness.py"
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def pr(number=57, base=gate.TARGET, draft=False, mergeable=True):
    return {"number": number, "title": "Synthetic integration candidate",
            "head": {"sha": "a" * 40, "ref": "integration/demo"},
            "base": {"ref": base}, "draft": draft,
            "mergeable": mergeable, "state": "open"}


def checks(names=None):
    names = names or sorted(gate.CORE)
    return [{"name": n, "head_sha": "a" * 40,
             "status": "completed", "conclusion": "success",
             "pull_requests": [{"number": 57}]}
            for n in names]


class IntegrationReadinessTest(unittest.TestCase):
    def test_passed_core_checks_are_only_a_manual_review_candidate(self):
        result = gate.assess(pr(), checks(), ["scripts/integration-readiness"])
        self.assertEqual("candidate_for_manual_review", result["status"])
        self.assertFalse(result["merge_executed"])
        self.assertIn("manual", result["warning"])

    def test_pending_or_failed_checks_always_block(self):
        for status, conclusion in [("queued", None), ("in_progress", None),
                                   ("completed", "failure"),
                                   ("completed", "cancelled"),
                                   ("completed", "neutral")]:
            with self.subTest(status=status, conclusion=conclusion):
                case = checks()
                case[0].update(status=status, conclusion=conclusion)
                result = gate.assess(pr(), case, ["README.md"])
                self.assertEqual("blocked", result["status"])

    def test_missing_core_suite_or_no_checks_block(self):
        for case in [[], checks(["business-platform"])]:
            self.assertEqual("blocked",
                             gate.assess(pr(), case, ["README.md"])["status"])

    def test_draft_conflict_and_unknown_mergeability_block(self):
        for props in [{"draft": True}, {"mergeable": False},
                      {"mergeable": None}]:
            with self.subTest(props=props):
                self.assertEqual("blocked", gate.assess(
                    pr(**props), checks(), ["README.md"])["status"])

    def test_stacked_pr_requires_its_parent_before_integration(self):
        for base, parent in [("development/battery-discovery-journey", 53),
                             ("development/ocpi-test-invoice-lineage", 59)]:
            case = pr(number=61, base=base)
            result = gate.assess(case, checks(), ["README.md"],
                                 parents={base: parent})
            self.assertEqual("blocked", result["status"])
            self.assertEqual(parent, result["dependency_pr"])
            self.assertIn("stacked_parent_pending:" + str(parent), result["blockers"])

    def test_old_pr_with_different_base_is_not_merged_blindly(self):
        result = gate.assess(pr(3, base="development/universal-integration"),
                             checks(), ["README.md"])
        self.assertIn("wrong_merge_target:development/universal-integration",
                      result["blockers"])

    def test_flutter_requires_its_own_two_hosted_platform_checks(self):
        for surface, expected in [
            ("apps/mobile/lib/api.dart", gate.NATIVE),
            ("apps/hasone-customer/lib/main.dart", gate.HASONE_NATIVE),
        ]:
            with self.subTest(surface=surface):
                self.assertEqual("blocked",
                    gate.assess(pr(), checks(), [surface])["status"])
                all_names = gate.CORE | expected
                self.assertEqual("candidate_for_manual_review",
                    gate.assess(pr(), checks(all_names), [surface])["status"])

    def test_mixed_flutter_changes_require_both_distinct_native_suites(self):
        files = ["apps/mobile/lib/main.dart",
                 "apps/hasone-customer/lib/main.dart"]
        for incomplete in [gate.CORE | gate.NATIVE,
                           gate.CORE | gate.HASONE_NATIVE]:
            self.assertEqual("blocked",
                gate.assess(pr(), checks(incomplete), files)["status"])
        all_suites = gate.CORE | gate.NATIVE | gate.HASONE_NATIVE
        self.assertEqual("candidate_for_manual_review",
            gate.assess(pr(), checks(all_suites), files)["status"])

    def test_express_and_disaster_recovery_have_specific_gates(self):
        examples = [("integrations/hasone-express/ocppMultiCharger.mjs",
                     "integration-guards"),
                    ("scripts/csms-recovery-smoke", "csms-recovery")]
        for path, expected in examples:
            with self.subTest(path=path):
                result = gate.assess(pr(), checks(), [path])
                self.assertIn(expected, result["missing_required"])

    def test_wrong_commit_or_missing_file_inventory_blocks(self):
        case = checks()
        case[0]["head_sha"] = "b" * 40
        result = gate.assess(pr(), case, ["README.md"])
        self.assertTrue(any(x.startswith("wrong_source_checks:") for x in result["blockers"]))
        self.assertEqual("blocked", gate.assess(pr(), checks(), [])["status"])

    def test_check_run_inventory_above_safe_limit_blocks(self):
        large = checks(["job-%d" % n for n in range(gate.CHECK_LIMIT)])
        result = gate.assess(pr(), large, ["README.md"])
        self.assertIn("check_run_limit_requires_review", result["blockers"])


    def test_push_only_checks_cannot_replace_pr_event_validation(self):
        push_checks = checks()
        for run in push_checks:
            run["pull_requests"] = []
        result = gate.assess(pr(), push_checks, ["README.md"])
        self.assertEqual("blocked", result["status"])
        self.assertIn("no_pr_associated_exact_head_checks", result["blockers"])
        self.assertEqual(len(push_checks), result["ignored_unassociated_checks"])

    def test_missing_pr_association_is_never_accepted_as_ci_evidence(self):
        unassociated = checks()
        for run in unassociated:
            del run["pull_requests"]
        result = gate.assess(pr(), unassociated, ["README.md"])
        self.assertEqual("blocked", result["status"])
        self.assertIn("no_pr_associated_exact_head_checks", result["blockers"])
        self.assertEqual(len(unassociated), result["ignored_unassociated_checks"])

    def test_missing_sha_cannot_be_counted_as_exact_source_validation(self):
        case = checks()
        del case[0]["head_sha"]
        result = gate.assess(pr(), case, ["README.md"])
        self.assertEqual("blocked", result["status"])
        self.assertTrue(any(reason.startswith("wrong_source_checks:")
                            for reason in result["blockers"]))

    def test_unrelated_cancelled_push_does_not_poison_valid_pr_checks(self):
        pr_checks = checks()
        for run in pr_checks:
            run["pull_requests"] = [{"number": 57}]
        cancelled = dict(pr_checks[0], status="completed",
                         conclusion="cancelled", pull_requests=[])
        result = gate.assess(pr(), pr_checks + [cancelled], ["README.md"])
        self.assertEqual("candidate_for_manual_review", result["status"])
        self.assertEqual(1, result["ignored_unassociated_checks"])

    def test_different_pr_checks_cannot_validate_this_candidate(self):
        other_checks = checks()
        for run in other_checks:
            run["pull_requests"] = [{"number": 52}]
        result = gate.assess(pr(number=57), other_checks, ["README.md"])
        self.assertEqual("blocked", result["status"])


    def test_targeted_stacked_child_audit_discovers_open_parent(self):
        parent = pr(number=59)
        parent["head"]["ref"] = "development/ocpi-test-invoice-lineage"
        child = pr(number=61, base=parent["head"]["ref"])

        class FakeGitHub:
            def __init__(self, *args, **kwargs):
                pass

            def get(self, suffix):
                if suffix == "/pulls/61":
                    return child
                raise AssertionError("Unexpected network endpoint " + suffix)

            def pages(self, suffix, key=None):
                if suffix == "/pulls?state=open":
                    return [parent, child]
                if suffix == "/pulls/61/files":
                    return [{"filename": "README.md"}]
                if suffix.startswith("/commits/"):
                    return checks()
                raise AssertionError("Unexpected collection " + suffix)

        with patch.object(gate, "GitHub", FakeGitHub):
            report = gate.inspect_live(only_pr=61)
        self.assertEqual(1, report["audited_prs"])
        self.assertEqual("blocked", report["prs"][0]["status"])
        self.assertEqual(59, report["prs"][0]["dependency_pr"])
        self.assertIn("stacked_parent_pending:59", report["prs"][0]["blockers"])


    def test_overlapping_active_agent_files_are_reported_without_merging(self):
        changes = {
            50: ["docs/AGENT2_PARALLEL_WORKSTREAM.md", "apps/mobile/lib/api.dart"],
            55: ["apps/mobile/lib/api.dart", ".github/workflows/mobile.yml"],
            59: [".github/workflows/mobile.yml", "services/business-api/routes/roaming_orders.php"],
        }
        overlaps = gate.shared_changed_files(changes)
        self.assertEqual([50, 55], overlaps["apps/mobile/lib/api.dart"])
        self.assertEqual([55, 59], overlaps[".github/workflows/mobile.yml"])
        self.assertNotIn("docs/AGENT2_PARALLEL_WORKSTREAM.md", overlaps)
        self.assertEqual("shared_code_or_schema",
                         gate.risk_rank(".github/workflows/mobile.yml"))
        self.assertEqual("shared_code_or_schema",
                         gate.risk_rank("services/business-api/routes/api.php"))
        self.assertEqual("shared_documentation",
                         gate.risk_rank("docs/PROGRESS.md"))

    def test_overlap_report_is_nonblocking_when_checks_are_truly_green(self):
        self.assertEqual("candidate_for_manual_review",
                         gate.assess(pr(), checks(gate.CORE | gate.NATIVE),
                                     ["apps/mobile/lib/main.dart"])["status"])
        # Shared paths do not establish an actual Git conflict by themselves.
        self.assertEqual({"apps/mobile/lib/main.dart": [53, 55]},
                         gate.shared_changed_files({
                             53: ["apps/mobile/lib/main.dart"],
                             55: ["apps/mobile/lib/main.dart"],
                         }))


if __name__ == "__main__":
    unittest.main()
