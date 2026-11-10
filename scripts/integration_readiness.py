#!/usr/bin/env python3
"""Read-only, fail-closed PR merge-candidate audit for the ten-agent EV team.

This tool NEVER merges, changes repository settings or bypasses checks.
A 'candidate' means manual exact-source, review, security and dependency
approval is still required before using GitHub's merge API.
"""
import argparse
import json
import os
from pathlib import Path
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPOSITORY = "creabizsalonspa-create/EV"
TARGET = "development/fullstack-continuation"
CORE = frozenset(("business-platform", "finance-postgres",
                  "connector-postgres", "staging-image"))
NATIVE = frozenset(("native (ubuntu-latest)", "native (macos-15)"))
HASONE_NATIVE = frozenset(("hasone-native (ubuntu-latest)",
                          "hasone-native (macos-15)"))
CHECK_LIMIT = 300



def load_pr_dependencies(path=None, target=TARGET):
    """Validate a reviewed dependency DAG; stale/invalid manifests fail closed."""
    filename = (Path(path) if path is not None else
                Path(__file__).resolve().parents[1] /
                "docs/integration/pr-dependencies.json")
    data = json.loads(filename.read_text())
    if (not isinstance(data, dict) or
            data.get("schema_version") != 1 or
            data.get("target_branch") != target or
            not isinstance(data.get("dependencies"), dict)):
        raise ValueError("Invalid ten-agent PR dependency manifest")
    graph = {}
    for raw_id, values in data["dependencies"].items():
        if not isinstance(raw_id, str) or not re.fullmatch(r"[1-9][0-9]*", raw_id):
            raise ValueError("Invalid PR dependency identity")
        number = int(raw_id)
        if (not isinstance(values, list) or not values or
                any(type(value) is not int or value <= 0 or value == number
                    for value in values) or len(values) != len(set(values))):
            raise ValueError("Invalid or self-referencing PR dependency")
        graph[number] = tuple(values)
    completed, visiting = set(), set()

    def visit(number):
        if number in visiting:
            raise ValueError("Cyclic PR dependency graph")
        if number in completed:
            return
        visiting.add(number)
        for parent in graph.get(number, ()):
            visit(parent)
        visiting.remove(number)
        completed.add(number)

    for number in graph:
        visit(number)
    return graph


def dependency_status(github, number, target, target_sha, active_prs):
    """Require GitHub merge AND an ancestor of the actual integration head."""
    if number in active_prs:
        return {"number": number, "integrated": False, "reason": "pr_still_open"}
    pr = github.get("/pulls/" + str(number))
    if (pr.get("merged_at") is None or
            pr.get("base", {}).get("ref") != target):
        return {"number": number, "integrated": False,
                "reason": "not_merged_into_integration_target"}
    merge_sha = pr.get("merge_commit_sha")
    if (not isinstance(merge_sha, str) or
            not re.fullmatch(r"[a-fA-F0-9]{40}", merge_sha)):
        return {"number": number, "integrated": False,
                "reason": "missing_verified_merge_sha"}
    if not isinstance(target_sha, str) or not re.fullmatch(
            r"[a-fA-F0-9]{40}", target_sha):
        return {"number": number, "integrated": False,
                "reason": "integration_head_unknown"}
    ancestry = github.get("/compare/" + merge_sha + "..." + target_sha)
    if ancestry.get("status") not in ("ahead", "identical"):
        return {"number": number, "integrated": False,
                "reason": "merge_not_in_integration_history"}
    return {"number": number, "integrated": True,
            "reason": "verified_merged_ancestor"}



def required_checks(paths):
    required = set(CORE)
    if any(p.startswith("apps/mobile/")
           or p == ".github/workflows/mobile.yml" for p in paths):
        required.update(NATIVE)
    if any(p.startswith("apps/hasone-customer/")
           or p == ".github/workflows/hasone-customer.yml" for p in paths):
        required.update(HASONE_NATIVE)
    if any(p.startswith("integrations/hasone-express/") for p in paths):
        required.add("integration-guards")
    if any(p.startswith("scripts/csms-recovery-smoke") for p in paths):
        required.add("csms-recovery")
    return required


def action_workflow_run_id(details_url, repo=REPOSITORY):
    """Only accept GitHub Actions job links for this exact repository."""
    if not isinstance(details_url, str):
        return None
    pattern = (r"https://github\.com/" + re.escape(repo) +
               r"/actions/runs/([1-9][0-9]*)/job/[1-9][0-9]*")
    match = re.fullmatch(pattern, details_url)
    return int(match.group(1)) if match else None


def verified_pr_event_ids(workflows, number, head):
    """A push check can be linked to a PR: the run's event must also be PR."""
    return {run["id"] for run in workflows
            if type(run.get("id")) is int and run["id"] > 0
            and run.get("event") == "pull_request"
            and run.get("head_sha") == head
            and isinstance(run.get("pull_requests"), list)
            and any(isinstance(link, dict) and link.get("number") == number
                    for link in run["pull_requests"])}


def assess(pr, check_runs, paths, parents=None, target=TARGET,
           pr_event_run_ids=None, repo=REPOSITORY,
           prerequisites=(), prerequisite_status=None):
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
    prerequisite_status = prerequisite_status or {}
    if prerequisites and any(n not in prerequisite_status for n in prerequisites):
        reasons.append("missing_pr_dependency_verification")
    unresolved = sorted(n for n in prerequisites if
                        not prerequisite_status.get(n, {}).get("integrated"))
    if unresolved:
        reasons.append("unintegrated_pr_dependencies:" +
                       ",".join(str(n) for n in unresolved))
    if not paths:
        reasons.append("missing_changed_file_inventory")
    # The check-runs API lists PUSH jobs associated with open PRs, too.
    # Association alone does not prove a pull_request-triggered workflow.
    # Require the job URL to belong to a verified pull_request workflow run
    # for this exact PR and head; unknown run provenance must fail closed.
    if pr_event_run_ids is None:
        reasons.append("missing_pr_event_workflow_inventory")
    approved = {x for x in (pr_event_run_ids or ())
                if type(x) is int and x > 0}
    linked = [r for r in check_runs
              if isinstance(r.get("pull_requests"), list)
              and any(isinstance(link, dict) and link.get("number") == number
                      for link in r["pull_requests"])]
    relevant = [r for r in linked
                if action_workflow_run_id(r.get("details_url"), repo) in approved]
    if not linked:
        reasons.append("no_pr_associated_exact_head_checks")
    if not relevant:
        reasons.append("no_pr_event_exact_head_checks")
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
        "base": base, "dependency_pr": dependency,
        "prerequisites": list(prerequisites),
        "dependency_evidence": [prerequisite_status.get(n, {"number": n,
                                 "integrated": False, "reason": "not_checked"})
                                for n in prerequisites],
        "draft": bool(pr.get("draft")),
        "mergeable": pr.get("mergeable"), "changed_file_count": len(paths),
        "check_count": len(relevant), "ignored_unassociated_checks":
            len(check_runs) - len(linked), "ignored_non_pr_event_checks":
            len(linked) - len(relevant), "missing_required": missing,
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


def inspect_live(repo=REPOSITORY, target=TARGET, limit=100, only_pr=None,
                 non_draft_target=False):
    if non_draft_target and only_pr is not None:
        raise ValueError("Select --pr or --non-draft-target, not both")
    github = GitHub(repo, os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN"))
    dependency_graph = load_pr_dependencies(target=target)
    open_prs = github.pages("/pulls?state=open")
    if len(open_prs) > limit:
        raise ValueError("Open PR count exceeds --limit; review pagination explicitly")
    prs = ([github.get("/pulls/" + str(only_pr))]
           if only_pr is not None else
           [p for p in open_prs if
            (not p.get("draft") and p.get("base", {}).get("ref") == target)]
           if non_draft_target else open_prs)
    # Even a targeted child PR audit must resolve its parent from all open
    # branches, rather than reporting a generic wrong-target warning.
    parents = {p["head"]["ref"]: p["number"] for p in open_prs}
    active = {p["number"]: p for p in open_prs}
    required_parents = {
        n for p in prs for n in dependency_graph.get(p["number"], ())
    }
    # Exact merged-ancestor attestation is needed only for prerequisites that
    # have disappeared from the open list; open prerequisites are blocked.
    target_sha = None
    if any(n not in active for n in required_parents):
        info = github.get("/branches/" + target)
        target_sha = info["commit"]["sha"]
    verified_dependencies = {
        n: dependency_status(github, n, target, target_sha, active)
        for n in sorted(required_parents)
    }
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
        # Independently attest the GitHub Actions run event. A push run
        # can contain pull_requests=[...] but must NEVER validate PR CI.
        workflows = github.pages(
            "/actions/runs?head_sha=" + before["head"]["sha"] +
            "&event=pull_request", key="workflow_runs")
        pr_event_ids = verified_pr_event_ids(
            workflows, number, before["head"]["sha"])
        evaluated = assess(
            before, checks, paths, parents=parents, target=target,
            pr_event_run_ids=pr_event_ids, repo=repo,
            prerequisites=dependency_graph.get(number, ()),
            prerequisite_status=verified_dependencies,
        )
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
            "audit_scope": ("single_pr" if only_pr is not None else
                             "non_draft_target" if non_draft_target else
                             "all_open_prs"),
            "open_prs_discovered": len(open_prs),
            "ready_for_manual_review": sum(r["status"] == "candidate_for_manual_review"
                                            for r in results), "prs": results,
            "declared_dependency_edges": sum(len(x) for x in
                                              dependency_graph.values()),
            "shared_file_overlaps": conflicts,
            "merge_executed": False}


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--repo", default=REPOSITORY)
    p.add_argument("--target", default=TARGET)
    p.add_argument("--pr", type=int)
    p.add_argument("--non-draft-target", action="store_true",
                   help="Audit only the non-draft PRs targeting integration")
    p.add_argument("--limit", type=int, default=100)
    args = p.parse_args(argv)
    try:
        result = inspect_live(repo=args.repo, target=args.target,
                              limit=args.limit, only_pr=args.pr,
                              non_draft_target=args.non_draft_target)
    except (ValueError, KeyError, HTTPError, URLError, TimeoutError) as error:
        print(json.dumps({"status": "blocked", "error": str(error),
                          "merge_executed": False}), file=sys.stderr)
        return 2
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
