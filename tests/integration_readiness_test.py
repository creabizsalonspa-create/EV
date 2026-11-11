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
             "pull_requests": [{"number": 57}],
             "details_url": ("https://github.com/creabizsalonspa-create/EV/"
                             "actions/runs/54321/job/98765")}
            for n in names]


def evaluate(p, runs, paths, **kwargs):
    """Fully attributable synthetic PR workflow for pure gate unit tests."""
    kwargs.setdefault("pr_event_run_ids", {54321})
    return gate.assess(p, runs, paths, **kwargs)


class IntegrationReadinessTest(unittest.TestCase):
    def test_passed_core_checks_are_only_a_manual_review_candidate(self):
        result = evaluate(pr(), checks(), ["scripts/integration-readiness"])
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
                result = evaluate(pr(), case, ["README.md"])
                self.assertEqual("blocked", result["status"])

    def test_missing_core_suite_or_no_checks_block(self):
        for case in [[], checks(["business-platform"])]:
            self.assertEqual("blocked",
                             evaluate(pr(), case, ["README.md"])["status"])

    def test_draft_conflict_and_unknown_mergeability_block(self):
        for props in [{"draft": True}, {"mergeable": False},
                      {"mergeable": None}]:
            with self.subTest(props=props):
                self.assertEqual("blocked", evaluate(
                    pr(**props), checks(), ["README.md"])["status"])

    def test_stacked_pr_requires_its_parent_before_integration(self):
        for base, parent in [("development/battery-discovery-journey", 53),
                             ("development/ocpi-test-invoice-lineage", 59)]:
            case = pr(number=61, base=base)
            result = evaluate(case, checks(), ["README.md"],
                                 parents={base: parent})
            self.assertEqual("blocked", result["status"])
            self.assertEqual(parent, result["dependency_pr"])
            self.assertIn("stacked_parent_pending:" + str(parent), result["blockers"])

    def test_old_pr_with_different_base_is_not_merged_blindly(self):
        result = evaluate(pr(3, base="development/universal-integration"),
                             checks(), ["README.md"])
        self.assertIn("wrong_merge_target:development/universal-integration",
                      result["blockers"])

    def test_express_and_disaster_recovery_have_specific_gates(self):
        examples = [("integrations/hasone-express/ocppMultiCharger.mjs",
                     "integration-guards"),
                    ("scripts/csms-recovery-smoke", "csms-recovery")]
        for path, expected in examples:
            with self.subTest(path=path):
                result = evaluate(pr(), checks(), [path])
                self.assertIn(expected, result["missing_required"])

    def test_wrong_commit_or_missing_file_inventory_blocks(self):
        case = checks()
        case[0]["head_sha"] = "b" * 40
        result = evaluate(pr(), case, ["README.md"])
        self.assertTrue(any(x.startswith("wrong_source_checks:") for x in result["blockers"]))
        self.assertEqual("blocked", evaluate(pr(), checks(), [])["status"])

    def test_check_run_inventory_above_safe_limit_blocks(self):
        large = checks(["job-%d" % n for n in range(gate.CHECK_LIMIT)])
        result = evaluate(pr(), large, ["README.md"])
        self.assertIn("check_run_limit_requires_review", result["blockers"])


    def test_push_only_checks_cannot_replace_pr_event_validation(self):
        push_checks = checks()
        for run in push_checks:
            run["pull_requests"] = []
        result = evaluate(pr(), push_checks, ["README.md"])
        self.assertEqual("blocked", result["status"])
        self.assertIn("no_pr_associated_exact_head_checks", result["blockers"])
        self.assertEqual(len(push_checks), result["ignored_unassociated_checks"])

    def test_missing_pr_association_is_never_accepted_as_ci_evidence(self):
        unassociated = checks()
        for run in unassociated:
            del run["pull_requests"]
        result = evaluate(pr(), unassociated, ["README.md"])
        self.assertEqual("blocked", result["status"])
        self.assertIn("no_pr_associated_exact_head_checks", result["blockers"])
        self.assertEqual(len(unassociated), result["ignored_unassociated_checks"])

    def test_missing_sha_cannot_be_counted_as_exact_source_validation(self):
        case = checks()
        del case[0]["head_sha"]
        result = evaluate(pr(), case, ["README.md"])
        self.assertEqual("blocked", result["status"])
        self.assertTrue(any(reason.startswith("wrong_source_checks:")
                            for reason in result["blockers"]))

    def test_unrelated_cancelled_push_does_not_poison_valid_pr_checks(self):
        pr_checks = checks()
        for run in pr_checks:
            run["pull_requests"] = [{"number": 57}]
        cancelled = dict(pr_checks[0], status="completed",
                         conclusion="cancelled", pull_requests=[])
        result = evaluate(pr(), pr_checks + [cancelled], ["README.md"])
        self.assertEqual("candidate_for_manual_review", result["status"])
        self.assertEqual(1, result["ignored_unassociated_checks"])

    def test_different_pr_checks_cannot_validate_this_candidate(self):
        other_checks = checks()
        for run in other_checks:
            run["pull_requests"] = [{"number": 52}]
        result = evaluate(pr(number=57), other_checks, ["README.md"])
        self.assertEqual("blocked", result["status"])


    def test_green_push_run_associated_with_pr_cannot_validate_pr_ci(self):
        # GitHub check_runs.pull_requests includes PRs on push-triggered jobs.
        # The workflow-run event is a separate, mandatory source of truth.
        wrong_event = checks()
        result = evaluate(pr(), wrong_event, ["README.md"],
                          pr_event_run_ids={99999})
        self.assertEqual("blocked", result["status"])
        self.assertIn("no_pr_event_exact_head_checks", result["blockers"])
        self.assertEqual(len(wrong_event), result["ignored_non_pr_event_checks"])

    def test_missing_workflow_inventory_and_missing_source_fail_closed(self):
        self.assertEqual("blocked",
            gate.assess(pr(), checks(), ["README.md"])["status"])
        for key in ("head_sha", "details_url"):
            malformed = checks()
            for run in malformed:
                run.pop(key)
            result = evaluate(pr(), malformed, ["README.md"])
            self.assertEqual("blocked", result["status"], key)

    def test_forged_cross_repository_check_link_cannot_validate(self):
        malformed = checks()
        for run in malformed:
            run["details_url"] = run["details_url"].replace(
                "/creabizsalonspa-create/EV/", "/other/EV/")
        result = evaluate(pr(), malformed, ["README.md"])
        self.assertEqual("blocked", result["status"])

    def test_workflow_ids_require_pr_event_exact_sha_and_association(self):
        runs = [
            {"id": 54321, "event": "pull_request", "head_sha": "a" * 40,
             "pull_requests": [{"number": 57}]},
            {"id": 9, "event": "push", "head_sha": "a" * 40,
             "pull_requests": [{"number": 57}]},
            {"id": 10, "event": "pull_request", "head_sha": "b" * 40,
             "pull_requests": [{"number": 57}]},
            {"id": 11, "event": "pull_request", "head_sha": "a" * 40,
             "pull_requests": [{"number": 52}]},
            {"id": True, "event": "pull_request", "head_sha": "a" * 40,
             "pull_requests": [{"number": 57}]}
        ]
        self.assertEqual({54321},
            gate.verified_pr_event_ids(runs, 57, "a" * 40))
        self.assertEqual("candidate_for_manual_review",
            evaluate(pr(), checks(), ["README.md"],
                     pr_event_run_ids=gate.verified_pr_event_ids(
                         runs, 57, "a" * 40))["status"])

    def test_targeted_stacked_child_audit_discovers_open_parent(self):
        parent = pr(number=59)
        parent["head"]["ref"] = "development/ocpi-test-invoice-lineage"
        child = pr(number=61, base=parent["head"]["ref"])
        security_parent = pr(number=64)
        security_parent["head"]["ref"] = "development/agent9-payment-return-csp-20261107"

        class FakeGitHub:
            def __init__(self, *args, **kwargs):
                pass

            def get(self, suffix):
                if suffix == "/pulls/61":
                    return child
                raise AssertionError("Unexpected network endpoint " + suffix)

            def pages(self, suffix, key=None):
                if suffix == "/pulls?state=open":
                    return [parent, security_parent, child]
                if suffix == "/pulls/61/files":
                    return [{"filename": "README.md"}]
                if suffix.startswith("/commits/"):
                    return checks()
                if suffix.startswith("/actions/runs?"):
                    return []
                raise AssertionError("Unexpected collection " + suffix)

        with patch.object(gate, "GitHub", FakeGitHub):
            report = gate.inspect_live(only_pr=61)
        self.assertEqual(1, report["audited_prs"])
        self.assertEqual("blocked", report["prs"][0]["status"])
        self.assertEqual(59, report["prs"][0]["dependency_pr"])
        self.assertIn("stacked_parent_pending:59", report["prs"][0]["blockers"])
        self.assertIn("unintegrated_pr_dependencies:59,64", report["prs"][0]["blockers"])


    def test_flutter_requires_its_own_two_hosted_platform_checks(self):
        for surface, expected in [
            ("apps/mobile/lib/api.dart", gate.NATIVE),
            ("apps/hasone-customer/lib/main.dart", gate.HASONE_NATIVE),
        ]:
            with self.subTest(surface=surface):
                self.assertEqual("blocked",
                    evaluate(pr(), checks(), [surface])["status"])
                all_names = gate.CORE | expected
                self.assertEqual("candidate_for_manual_review",
                    evaluate(pr(), checks(all_names), [surface])["status"])


    def test_mixed_flutter_changes_require_both_distinct_native_suites(self):
        files = ["apps/mobile/lib/main.dart",
                 "apps/hasone-customer/lib/main.dart"]
        for incomplete in [gate.CORE | gate.NATIVE,
                           gate.CORE | gate.HASONE_NATIVE]:
            self.assertEqual("blocked",
                evaluate(pr(), checks(incomplete), files)["status"])
        all_suites = gate.CORE | gate.NATIVE | gate.HASONE_NATIVE
        self.assertEqual("candidate_for_manual_review",
            evaluate(pr(), checks(all_suites), files)["status"])


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
                         evaluate(pr(), checks(gate.CORE | gate.NATIVE),
                                     ["apps/mobile/lib/main.dart"])["status"])
        # Shared paths do not establish an actual Git conflict by themselves.
        self.assertEqual({"apps/mobile/lib/main.dart": [53, 55]},
                         gate.shared_changed_files({
                             53: ["apps/mobile/lib/main.dart"],
                             55: ["apps/mobile/lib/main.dart"],
                         }))



if __name__ == "__main__":
    unittest.main()
