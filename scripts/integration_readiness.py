#!/usr/bin/env python3
"""Read-only, fail-closed PR merge-candidate audit for the ten-agent EV team.

This tool NEVER merges, changes repository settings or bypasses checks.
A 'candidate' means manual exact-source, review, security and dependency
approval is still required before using GitHub's merge API.
"""
import argparse
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPOSITORY = "creabizsalonspa-create/EV"
TARGET = "development/fullstack-continuation"
CORE = frozenset(("business-platform", "finance-postgres",
                  "connector-postgres", "staging-image"))
NATIVE = frozenset(("native (ubuntu-latest)", "native (macos-15)"))
CHECK_LIMIT = 300


def required_checks(paths):
    required = set(CORE)
    if any(p.startswith(("apps/mobile/", "apps/hasone-customer/"))
           or p == ".github/workflows/mobile.yml"
           or p == ".github/workflows/hasone-customer.yml" for p in paths):
        required.update(NATIVE)
    if any(p.startswith("integrations/hasone-express/") for p in paths):
        required.add("integration-guards")
    if any(p.startswith("scripts/csms-recovery-smoke") for p in paths):
        required.add("csms-recovery")
    return required


def assess(pr, check_runs, paths, parents=None, target=TARGET):
    """Pure, deliberately conservative eligibility evaluation."""
    parents = parents or {}
    number = pr["number"]
    head = pr["head"]["sha"]
    base = pr["base"]["ref"]
    reasons = []
    dependency = parents.get(base) if base != target else None

    if pr.get("state") != "open":
        reasons.append("not_open")
    if pr.get("draft"):
        reasons.append("draft")
    if base != target:
        reasons.append("stacked_parent_pending:" + str(dependency)
                       if dependency else "wrong_merge_target:" + base)
    if pr.get("mergeable") is not True:
        reasons.append("conflict_or_mergeability_unknown")
    if not paths:
        reasons.append("missing_changed_file_inventory")
    # GitHub may return both push and PR check suites for the same commit.
    # Only checks associated with THIS open PR can satisfy its merge gate.
    # Unassociated push runs must not conceal absent PR validation or block
    # a clean PR because a separate push run was superseded/cancelled.
    relevant = [r for r in check_runs
                if isinstance(r.get("pull_requests"), list)
                and any(isinstance(link, dict) and link.get("number") == number
                        for link in r["pull_requests"])]
    if not relevant:
        reasons.append("no_pr_associated_exact_head_checks")
    if len(check_runs) >= CHECK_LIMIT:
        reasons.append("check_run_limit_requires_review")
    names = {r.get("name") for r in relevant}
    missing = sorted(required_checks(paths) - names)
    if missing:
        reasons.append("missing_required_checks:" + ",".join(missing))

    failed = []
    waiting = []
    wrong_source = []
    for r in relevant:
        name = str(r.get("name") or "unnamed")
        if r.get("head_sha") != head:
            # A missing commit SHA is not source evidence. Fail closed.
            wrong_source.append(name)
        elif r.get("status") != "completed":
            waiting.append(name)
        elif r.get("conclusion") != "success":
            failed.append(name + ":" + str(r.get("conclusion")))
    if wrong_source:
        reasons.append("wrong_source_checks:" + ",".join(sorted(set(wrong_source))))
    if waiting:
        reasons.append("pending_checks:" + ",".join(sorted(set(waiting))))
    if failed:
        reasons.append("non_successful_checks:" + ",".join(sorted(set(failed))))

    return {
        "pr": number, "title": pr.get("title"), "head_sha": head,
        "base": base, "dependency_pr": dependency, "draft": bool(pr.get("draft")),
        "mergeable": pr.get("mergeable"), "changed_file_count": len(paths),
        "check_count": len(relevant), "ignored_unassociated_checks":
            len(check_runs) - len(relevant), "missing_required": missing,
        "status": "candidate_for_manual_review" if not reasons else "blocked",
        "blockers": reasons,
        "merge_executed": False,
        "warning": "Candidates require manual human/code review and exact-source PR-event "
                   "CI verification; this is not automated merge authorization.",
    }


def shared_changed_files(changes):
    """Read-only overlap map: overlapping paths need coordination, not auto-blocks."""
    owners = {}
    for number, paths in changes.items():
        for path in set(paths):
            owners.setdefault(path, set()).add(number)
    return {path: sorted(numbers)
            for path, numbers in sorted(owners.items())
            if len(numbers) > 1}


def risk_rank(path):
    if path.startswith(("services/business-api/database/migrations/",
                        "services/business-api/app/Services/Finance/",
                        "services/business-api/routes/",
                        ".github/workflows/",
                        "apps/mobile/lib/",
                        "apps/hasone-customer/lib/",
                        "integrations/hasone-express/")):
        return "shared_code_or_schema"
    if path.startswith("docs/"):
        return "shared_documentation"
    return "shared_source"



class GitHub:
    def __init__(self, repo, token=None):
        if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo):
            raise ValueError("Invalid owner/repository")
        self.repo = repo
        self.token = token

    def get(self, suffix):
        url = "https://api.github.com/repos/" + self.repo + suffix
        headers = {"Accept": "application/vnd.github+json",
                   "User-Agent": "ev-integration-readiness/1",
                   "X-GitHub-Api-Version": "2022-11-28"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        with urlopen(Request(url, headers=headers), timeout=18) as response:
            return json.load(response)

    def pages(self, suffix, key=None):
        out = []
        for page in range(1, 6):
            sep = "&" if "?" in suffix else "?"
            result = self.get(suffix + sep + "per_page=100&page=" + str(page))
            items = result[key] if key else result
            if not isinstance(items, list):
                raise ValueError("Unexpected GitHub collection shape")
            out.extend(items)
            if len(items) < 100:
                return out
        raise ValueError("Pagination exceeded safe review limit")


def inspect_live(repo=REPOSITORY, target=TARGET, limit=30, only_pr=None):
    github = GitHub(repo, os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN"))
    open_prs = github.pages("/pulls?state=open")
    if len(open_prs) > limit:
        raise ValueError("Open PR count exceeds --limit; review pagination explicitly")
    prs = ([github.get("/pulls/" + str(only_pr))]
           if only_pr is not None else open_prs)
    # Even a targeted child PR audit must resolve its parent from all open
    # branches, rather than reporting a generic wrong-target warning.
    parents = {p["head"]["ref"]: p["number"] for p in open_prs}
    results = []
    changed_by_pr = {}
    for item in prs:
        number = item["number"]
        # Re-read detail: list results do not expose reliable mergeability.
        before = github.get("/pulls/" + str(number))
        files = github.pages("/pulls/" + str(number) + "/files")
        paths = [f["filename"] for f in files]
        changed_by_pr[number] = paths
        checks = github.pages("/commits/" + before["head"]["sha"] +
                              "/check-runs", key="check_runs")
        evaluated = assess(before, checks, paths, parents=parents, target=target)
        # Guard against moving heads while reading separate API resources.
        after = github.get("/pulls/" + str(number))
        if (after["head"]["sha"] != before["head"]["sha"]
                or after["base"]["ref"] != before["base"]["ref"]):
            evaluated["status"] = "blocked"
            evaluated["blockers"].append("head_or_base_changed_during_inspection")
        results.append(evaluated)
    overlap = shared_changed_files(changed_by_pr)
    for evaluated in results:
        own = evaluated["pr"]
        matches = [path for path, numbers in overlap.items() if own in numbers]
        evaluated["shared_changed_files"] = len(matches)
        evaluated["overlapping_prs"] = sorted({
            number for path in matches for number in overlap[path]
            if number != own
        })
    conflicts = [
        {"path": path, "prs": numbers, "risk": risk_rank(path)}
        for path, numbers in overlap.items()
    ]
    return {"repository": repo, "target": target, "audited_prs": len(results),
            "ready_for_manual_review": sum(r["status"] == "candidate_for_manual_review"
                                            for r in results), "prs": results,
            "shared_file_overlaps": conflicts,
            "merge_executed": False}


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--repo", default=REPOSITORY)
    p.add_argument("--target", default=TARGET)
    p.add_argument("--pr", type=int)
    p.add_argument("--limit", type=int, default=30)
    args = p.parse_args(argv)
    try:
        result = inspect_live(repo=args.repo, target=args.target,
                              limit=args.limit, only_pr=args.pr)
    except (ValueError, KeyError, HTTPError, URLError, TimeoutError) as error:
        print(json.dumps({"status": "blocked", "error": str(error),
                          "merge_executed": False}), file=sys.stderr)
        return 2
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
