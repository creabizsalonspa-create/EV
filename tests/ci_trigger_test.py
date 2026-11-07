"""Prevent ten-agent push storms without weakening mandatory PR/default-branch CI.

This uses only stdlib because it runs before Composer/npm/bootstrap is installed.
"""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = {
    "ci.yml": ("business-platform", "finance-postgres", "connector-postgres", "staging-image"),
    "finance-commercial.yml": ("financial-browser",),
    "mobile.yml": ("native",),
    "hasone-customer.yml": ("native",),
}
INTEGRATION_BRANCH = "development/fullstack-continuation"
DEFAULT_BRANCH = "development/platform-foundation"


def top_level_section(content, key):
    """Return a single top-level YAML section without a YAML dependency."""
    lines = content.splitlines()
    header = f"{key}:"
    if lines.count(header) != 1:
        raise AssertionError(f"{key}: expected exactly one section")
    start = lines.index(header) + 1
    end = next(
        (idx for idx in range(start, len(lines)) if re.match(r"^[^ #][^:]*:", lines[idx])),
        len(lines),
    )
    return "\n".join(lines[start:end])


def event_subsection(trigger, event):
    lines = trigger.splitlines()
    header = f"  {event}:"
    if lines.count(header) != 1:
        raise AssertionError(f"{event}: expected exactly one trigger")
    start = lines.index(header) + 1
    end = next(
        (idx for idx in range(start, len(lines)) if re.match(r"^  [a-z_]+:", lines[idx])),
        len(lines),
    )
    return "\n".join(lines[start:end])


class CITriggerTest(unittest.TestCase):
    def test_pr_and_integration_push_validation_are_both_retained(self):
        for filename, expected_jobs in WORKFLOWS.items():
            with self.subTest(workflow=filename):
                workflow = (ROOT / ".github/workflows" / filename).read_text()
                trigger = top_level_section(workflow, "on")
                pull_request = event_subsection(trigger, "pull_request")
                push = event_subsection(trigger, "push")
                self.assertNotRegex(pull_request, r"(?m)^    branches(?:-ignore)?:")
                self.assertEqual(
                    re.findall(r"(?m)^    branches: \[(.+)\]$", push),
                    [f"{INTEGRATION_BRANCH}, {DEFAULT_BRANCH}"],
                    "Integration and repository default pushes must run; feature branches use mandatory PR checks",
                )
                for job in expected_jobs:
                    self.assertRegex(workflow, rf"(?m)^  {re.escape(job)}:")
                self.assertIn("concurrency:", workflow)

    def test_hasone_client_build_pushes_match_relevant_pr_paths(self):
        workflow = (ROOT / ".github/workflows/hasone-customer.yml").read_text()
        trigger = top_level_section(workflow, "on")
        expected_paths = (
            "    paths: ['apps/hasone-customer/**', "
            "'.github/workflows/hasone-customer.yml']"
        )
        self.assertIn(expected_paths, event_subsection(trigger, "pull_request"))
        self.assertIn(expected_paths, event_subsection(trigger, "push"))
        self.assertIn("  workflow_dispatch:", trigger)

    def test_superseded_work_cannot_cancel_a_distinct_pr_event(self):
        for filename in WORKFLOWS:
            with self.subTest(workflow=filename):
                workflow = (ROOT / ".github/workflows" / filename).read_text()
                concurrency = top_level_section(workflow, "concurrency")
                self.assertIn("github.workflow", concurrency)
                self.assertIn("github.event_name", concurrency)
                self.assertIn("github.event.pull_request.number || github.ref", concurrency)
                self.assertRegex(concurrency, r"(?m)^  cancel-in-progress: true$")


    def test_all_required_jobs_have_bounded_runner_time(self):
        # Ten concurrent agents can leave many queued jobs behind. Unbounded
        # jobs consume scarce runners after they start and delay newer commits.
        # Preserve every mandatory job; never skip tests to optimize the queue.
        budgets = {
            "ci.yml": {"business-platform": 120, "finance-postgres": 60,
                       "connector-postgres": 40, "staging-image": 20},
            "mobile.yml": {"native": 150},
            "hasone-customer.yml": {"native": 90},
            "finance-commercial.yml": {"financial-browser": 90},
        }
        for filename, jobs in budgets.items():
            content = (ROOT / ".github/workflows" / filename).read_text()
            section = top_level_section(content, "jobs")
            for name, budget in jobs.items():
                with self.subTest(workflow=filename, job=name):
                    lines = section.splitlines()
                    header = f"  {name}:"
                    self.assertEqual(1, lines.count(header), f"Missing job {name}")
                    start = lines.index(header) + 1
                    end = next(
                        (idx for idx in range(start, len(lines))
                         if re.match(r"^  [A-Za-z0-9_-]+:", lines[idx])),
                        len(lines),
                    )
                    configured = [
                        line.strip().removeprefix("timeout-minutes: ")
                        for line in lines[start:end]
                        if line.startswith("    timeout-minutes: ")
                    ]
                    self.assertEqual([str(budget)], configured)
                    self.assertLessEqual(budget, 180)
                    self.assertGreaterEqual(budget, 20)


    def test_hasone_dependency_caches_preserve_exact_lockfile_and_flutter_pin(self):
        workflow = (ROOT / ".github/workflows/hasone-customer.yml").read_text()
        self.assertEqual(1, workflow.count("name: Cache lockfile-pinned Dart packages"))
        self.assertIn("uses: actions/cache@v4", workflow)
        self.assertIn("path: ~/.pub-cache", workflow)
        self.assertIn(
            "key: ${{ runner.os }}-hasone-3.35.7-pub-"
            "${{ hashFiles('apps/hasone-customer/pubspec.lock') }}",
            workflow,
        )
        self.assertNotIn("restore-keys:", workflow,
                         "Old lockfile dependency caches must not be accepted")
        self.assertIn("cache: gradle", workflow)
        self.assertIn(
            "apps/hasone-customer/android/gradle/wrapper/gradle-wrapper.properties",
            workflow,
        )
        self.assertIn("flutter pub get --enforce-lockfile", workflow)
        self.assertIn("flutter test --no-pub", workflow)
        self.assertIn("adc901062556672b4138e18a4dc62a4be8f4b3c2", workflow)
        self.assertIn("flutter build apk --debug", workflow)
        self.assertIn("flutter build ios --simulator --no-codesign", workflow)


    def test_queue_maintenance_requires_explicit_manual_dispatch(self):
        workflow = (
            ROOT / ".github/workflows/queued-ci-maintenance.yml"
        ).read_text()
        trigger = top_level_section(workflow, "on")
        self.assertIn("  workflow_dispatch:", trigger)
        self.assertNotIn("  pull_request:", trigger)
        self.assertNotIn("  push:", trigger)
        self.assertIn("default: false", trigger)
        self.assertIn('options: ["1", "5", "10", "25", "50"]', trigger)

        jobs = top_level_section(workflow, "jobs")
        self.assertIn("  dry-run:", jobs)
        self.assertIn("  apply:", jobs)
        before, apply = jobs.split("  apply:", 1)
        self.assertIn("actions: read", before)
        self.assertNotIn("actions: write", before)
        self.assertIn("if: ${{ inputs.apply == true }}", apply)
        self.assertIn("needs: dry-run", apply)
        self.assertIn("actions: write", apply)
        self.assertIn("test \"$GITHUB_EVENT_NAME\" = workflow_dispatch", apply)
        self.assertIn("test \"$GITHUB_REPOSITORY\" = creabizsalonspa-create/EV", apply)
        self.assertIn("test \"$APPLY\" = true", apply)
        self.assertIn("--apply --summary-only", apply)
        self.assertIn("--page-limit 12", apply)
        self.assertRegex(workflow, r"(?m)^  cancel-in-progress: false$")


if __name__ == "__main__":
    unittest.main()
