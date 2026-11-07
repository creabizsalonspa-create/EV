#!/usr/bin/env python3
"""Conservative GitHub Actions queue triage for busy EV feature branches.

Read-only by default. Only stale *queued* runs are eligible. The tool never
touches running jobs, active PR-head checks, protected branches, or production.
To cancel a bounded set, an operator must explicitly supply --apply and an
Actions-write GH_TOKEN/GITHUB_TOKEN. Do not run --apply in pull_request CI.
"""
import argparse
import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPO = "creabizsalonspa-create/EV"
PROTECTED = frozenset((
    "development/fullstack-continuation",
    "development/platform-foundation",
    "main", "master",
))
MAX_PAGE_COUNT = 12


def _int(value):
    return type(value) is int and value > 0


def _same_repository(pr, repo):
    source = pr.get("head", {}).get("repo") or {}
    # PR list responses include head.repo.full_name. An absent head repo
    # is not positive proof that this is a same-repository branch.
    return source.get("full_name") == repo


def build_context(prs, queued, repo=REPO):
    """Only active, same-repo PR heads and their PR-event runs are trusted."""
    heads = {}
    by_number = {}
    for pr in prs:
        if pr.get("state") != "open" or not _int(pr.get("number")):
            continue
        by_number[pr["number"]] = pr
        if not _same_repository(pr, repo):
            continue
        branch = pr.get("head", {}).get("ref")
        sha = pr.get("head", {}).get("sha")
        if isinstance(branch, str) and isinstance(sha, str) and len(sha) == 40:
            heads.setdefault(branch, []).append((pr["number"], sha))
    paired = set()
    for run in queued:
        if (run.get("event") != "pull_request" or
                not _int(run.get("workflow_id"))):
            continue
        for link in run.get("pull_requests") or ():
            if not isinstance(link, dict):
                continue
            number = link.get("number")
            if number in by_number and run.get("head_sha") == by_number[number]["head"]["sha"]:
                paired.add((number, run["head_sha"], run["workflow_id"]))
    return heads, by_number, paired


def classify(run, heads, by_number, paired, repo=REPO):
    """Return a narrowly justified obsolete-work reason, or None to protect."""
    if run.get("status") != "queued" or not _int(run.get("id")):
        return None
    if (run.get("repository") or {}).get("full_name", repo) != repo:
        return None
    branch, sha, event = (
        run.get("head_branch"), run.get("head_sha"), run.get("event"))
    if not isinstance(branch, str) or not isinstance(sha, str) or len(sha) != 40:
        return None
    if (branch in PROTECTED or branch.startswith(("release/", "production/", "staging/"))
            or not branch.startswith(("development/", "integration/"))):
        return None

    if event == "pull_request":
        links = run.get("pull_requests")
        if not isinstance(links, list) or not links:
            return None
        numbers = {p.get("number") for p in links if isinstance(p, dict)}
        if not numbers or any(n not in by_number for n in numbers):
            return None
        if all(by_number[n].get("head", {}).get("sha") != sha for n in numbers):
            return "superseded_pr_head"
        return None

    if event != "push":
        return None
    matching = heads.get(branch)
    if not matching:
        return None
    if all(current_sha != sha for _, current_sha in matching):
        return "superseded_feature_push"

    # Even a current-head push is duplicate work when exact-head PR-event
    # validation for the SAME workflow exists for every active PR sharing
    # that branch. Never cancel the only available workflow.
    workflow_id = run.get("workflow_id")
    if (not _int(workflow_id)
            or not all((number, sha, workflow_id) in paired
                       for number, current_sha in matching
                       if current_sha == sha)):
        return None
    if not any(current_sha == sha for _, current_sha in matching):
        return None
    return "duplicate_feature_push_with_pr_event"


class Github:
    def __init__(self, repo=REPO, token=None):
        if repo != REPO:
            raise ValueError("This triage is restricted to the EV repository")
        self.repo = repo
        self.token = token

    def request(self, suffix, method="GET"):
        if method != "GET" and not self.token:
            raise PermissionError("Actions-write token is required for cancellation")
        req = Request(
            "https://api.github.com/repos/" + self.repo + suffix,
            method=method, headers={
                "Accept": "application/vnd.github+json",
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "creabiz-ev-queue-triage/1",
                **({"Authorization": "Bearer " + self.token} if self.token else {}),
            },
        )
        with urlopen(req, timeout=20) as response:
            return json.load(response) if method == "GET" else response.status

    def pages(self, suffix, key, limit=MAX_PAGE_COUNT):
        out = []
        for index in range(1, limit + 1):
            sep = "&" if "?" in suffix else "?"
            payload = self.request(suffix + sep + "per_page=100&page=" + str(index))
            rows = payload.get(key)
            if not isinstance(rows, list):
                raise ValueError("Unexpected GitHub pagination response")
            out.extend(rows)
            if len(rows) < 100:
                return out, False
        return out, True


def inspect(github, page_limit=MAX_PAGE_COUNT):
    # Never claim an exhaustive audit if GitHub pagination was truncated.
    prs, prs_truncated = github.pages("/pulls?state=open", "pull_requests",
                                       limit=page_limit)
    # GitHub's PR list returns a JSON array rather than a named collection.
    # This is handled by Github.pages below without weakening validation.
    runs, runs_truncated = github.pages("/actions/runs?status=queued",
                                         "workflow_runs", limit=page_limit)
    if prs_truncated:
        raise ValueError("Open PR enumeration truncated; refuse stale-run decisions")
    heads, by_number, paired = build_context(prs, runs, github.repo)
    candidates = []
    for run in runs:
        reason = classify(run, heads, by_number, paired, github.repo)
        if reason:
            candidates.append({
                "id": run["id"], "reason": reason,
                "branch": run["head_branch"], "sha": run["head_sha"],
                "event": run["event"], "workflow": run.get("name", ""),
            })
    return {
        "repository": github.repo, "queued_scanned": len(runs),
        "queue_scan_truncated": runs_truncated,
        "open_pr_count": len(prs),
        "candidate_count": len(candidates),
        "candidates": candidates,
        "cancellations_executed": 0,
    }


def cancel_candidates(github, report, max_cancel):
    """Re-check original run and mutable PR state before each operator cancel."""
    if not github.token:
        raise PermissionError("Explicit Actions-write token required for --apply")
    if report["queue_scan_truncated"]:
        raise ValueError("Truncated queue inventory; refuse cancellation")
    if not _int(max_cancel) or max_cancel > 50:
        raise ValueError("Cancellation batch limit must be from 1 to 50")
    cancelled, skipped = [], []
    for candidate in report["candidates"][:max_cancel]:
        # Never apply a stale dry-run decision after another agent pushes.
        prs, truncated = github.pages("/pulls?state=open", "pull_requests")
        if truncated:
            raise ValueError("PR inventory changed or truncated before cancellation")
        queued, queue_truncated = github.pages("/actions/runs?status=queued",
                                                "workflow_runs")
        if queue_truncated:
            raise ValueError("Queued runs exceeded safe scan limit")
        heads, by_number, paired = build_context(prs, queued, github.repo)
        current = next((r for r in queued if r.get("id") == candidate["id"]), None)
        if (current is None or
                classify(current, heads, by_number, paired, github.repo)
                != candidate["reason"]):
            skipped.append(candidate["id"])
            continue
        # Last status/refetch guard before POST; never cancel in-progress jobs.
        latest = github.request("/actions/runs/" + str(candidate["id"]))
        if (latest.get("status") != "queued"
                or latest.get("head_sha") != candidate["sha"]
                or latest.get("event") != candidate["event"]):
            skipped.append(candidate["id"])
            continue
        github.request("/actions/runs/" + str(candidate["id"]) + "/cancel",
                       method="POST")
        cancelled.append(candidate["id"])
    return cancelled, skipped


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--apply", action="store_true",
                   help="Explicitly cancel only revalidated superseded queued runs")
    p.add_argument("--max-cancel", type=int, default=10)
    p.add_argument("--page-limit", type=int, default=MAX_PAGE_COUNT)
    args = p.parse_args(argv)
    if args.page_limit < 1 or args.page_limit > MAX_PAGE_COUNT:
        p.error("--page-limit must be within 1..12")
    try:
        token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
        if args.apply and not token:
            raise PermissionError("No Actions-write token; refusing --apply")
        github = Github(token=token)
        report = inspect(github, args.page_limit)
        if args.apply:
            cancelled, skipped = cancel_candidates(github, report, args.max_cancel)
            report["cancellations_executed"] = len(cancelled)
            report["cancelled_ids"] = cancelled
            report["skipped_on_recheck"] = skipped
        print(json.dumps(report, indent=2, sort_keys=True))
        return 0
    except (HTTPError, URLError, TimeoutError, ValueError, KeyError,
            PermissionError, OSError) as exc:
        print(json.dumps({"error": type(exc).__name__ + ": " + str(exc),
                          "cancellations_executed": 0}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
