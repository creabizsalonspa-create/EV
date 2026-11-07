"""Prevent a fifth-agent push storm without weakening mandatory pull-request CI.

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
                    [INTEGRATION_BRANCH],
                    "Integration pushes must run, while feature branches use mandatory PR checks",
                )
                for job in expected_jobs:
                    self.assertRegex(workflow, rf"(?m)^  {re.escape(job)}:")
                self.assertIn("concurrency:", workflow)

    def test_superseded_work_cannot_cancel_a_distinct_pr_event(self):
        for filename in WORKFLOWS:
            with self.subTest(workflow=filename):
                workflow = (ROOT / ".github/workflows" / filename).read_text()
                concurrency = top_level_section(workflow, "concurrency")
                self.assertIn("github.workflow", concurrency)
                self.assertIn("github.event_name", concurrency)
                self.assertIn("github.event.pull_request.number || github.ref", concurrency)
                self.assertRegex(concurrency, r"(?m)^  cancel-in-progress: true$")


if __name__ == "__main__":
    unittest.main()
