#!/usr/bin/env python3
"""Conservative GitHub Actions queue triage for busy EV feature branches.

Read-only by default. Only stale *queued* runs are eligible. The tool never
touches running jobs, active PR-head checks, protected branches, or production.
To cancel a bounded set, an operator must explicitly supply --apply and an
Actions-write GH_TOKEN/GITHUB_TOKEN. Do not run --apply in pull_request CI.
"""
import argparse
from collections import Counter
import json
import os
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

REPO = "creabizsalonspa-create/EV"
PROTECTED = frozenset((
    "development/fullstack-continuation",
    "development/platform-foundation",
    "main", "master",
))
MAX_PAGE_COUNT = 30


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
    if (run.get("repository") or {}).get("full_name") != repo:
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
        if (not numbers or any(n not in by_number for n in numbers)
                or any(not _same_repository(by_number[n], repo)
                       for n in numbers)):
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
        """Never treat GitHub's 1,000-run result ceiling as a complete queue.

        Some Actions responses report >1,000 queued runs on page one but
        return an empty page eleven. Compare the reported total with every
        row actually retrieved, including when a later page reports zero.
        """
        out = []
        expected_total = None
        for index in range(1, limit + 1):
            sep = "&" if "?" in suffix else "?"
            payload = self.request(suffix + sep + "per_page=100&page=" + str(index))
            if isinstance(payload, dict):
                reported = payload.get("total_count")
                if type(reported) is int and reported >= 0:
                    expected_total = max(expected_total or 0, reported)
            rows = payload if isinstance(payload, list) else payload.get(key)
            if not isinstance(rows, list):
                raise ValueError("Unexpected GitHub pagination response")
            out.extend(rows)
            if len(rows) < 100:
                return out, expected_total is not None and len(out) < expected_total
        return out, True


    def queued_pages(self, limit=MAX_PAGE_COUNT):
        """Recover older queued runs hidden behind GitHub's 1,000-result cap.

        Never authorize cancellation if result IDs, pagination totals or
        creation timestamps cannot be reconciled to a fresh API count.
        """
        source = "/actions/runs?status=queued"
        latest, incomplete = self.pages(source, "workflow_runs", limit=limit)
        if not incomplete:
            return latest, False
        if not latest:
            return [], True

        by_id = {}
        for item in latest:
            if not _int(item.get("id")):
                return latest, True
            by_id[item["id"]] = item
        if len(by_id) != len(latest):
            return latest, True

        def oldest(items):
            stamps = [item.get("created_at") for item in items]
            if any(not isinstance(x, str) or
                   not re.fullmatch(
                       r"[0-9]{4}-[0-9]{2}-[0-9]{2}T"
                       r"[0-9]{2}:[0-9]{2}:[0-9]{2}Z", x)
                   for x in stamps):
                return None
            return min(stamps)

        cutoff = oldest(latest)
        if cutoff is None:
            return latest, True
        # Include the cutoff second twice and deduplicate by run ID to avoid
        # dropping runs that share a timestamp at the pagination boundary.
        for _ in range(20):
            older, still_incomplete = self.pages(
                source + "&created=1970-01-01T00:00:00Z.." + cutoff,
                "workflow_runs", limit=limit)
            if not older:
                return list(by_id.values()), True
            old_cutoff = oldest(older)
            if old_cutoff is None:
                return list(by_id.values()), True
            for run in older:
                if not _int(run.get("id")):
                    return list(by_id.values()), True
                by_id[run["id"]] = run
            if not still_incomplete:
                break
            if old_cutoff >= cutoff:
                return list(by_id.values()), True
            cutoff = old_cutoff
        else:
            return list(by_id.values()), True

        # Other agents may push/cancel work during scanning; never authorize
        # action using a partial or temporally inconsistent API snapshot.
        final = self.request(source + "&per_page=1")
        reported = final.get("total_count")
        result = list(by_id.values())
        if type(reported) is not int or reported != len(result):
            return result, True
        return result, False


def inspect(github, page_limit=MAX_PAGE_COUNT):
    # Never claim an exhaustive audit if GitHub pagination was truncated.
    prs, prs_truncated = github.pages("/pulls?state=open", "pull_requests",
                                       limit=page_limit)
    # GitHub's PR list returns a JSON array rather than a named collection.
    # This is handled by Github.pages below without weakening validation.
    runs, runs_truncated = github.queued_pages(limit=page_limit)
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
    """Recheck each queued run before an explicitly authorized cancellation.

    Return exact partial outcomes even if GitHub rejects a later action.
    This does not grant Actions write access to pull-request workflows.
    """
    if not github.token:
        raise PermissionError("Explicit Actions-write token required for --apply")
    if report["queue_scan_truncated"]:
        raise ValueError("Truncated queue inventory; refuse cancellation")
    if not _int(max_cancel) or max_cancel > 50:
        raise ValueError("Cancellation batch limit must be from 1 to 50")
    cancelled, skipped, errors = [], [], []
    for candidate in report["candidates"][:max_cancel]:
        try:
            # Never apply a stale dry-run decision after another agent pushes.
            prs, truncated = github.pages("/pulls?state=open", "pull_requests")
            if truncated:
                raise ValueError("PR inventory truncated before cancellation")
            queued, queue_truncated = github.queued_pages()
            if queue_truncated:
                raise ValueError("Queued runs exceeded safe scan limit")
            heads, by_number, paired = build_context(prs, queued, github.repo)
            current = next((r for r in queued if r.get("id") == candidate["id"]),
                           None)
            if (current is None or
                    classify(current, heads, by_number, paired, github.repo)
                    != candidate["reason"]):
                skipped.append(candidate["id"])
                continue
            # Last status/refetch guard before POST; never intentionally
            # cancel in-progress jobs. GitHub state can still race between
            # GET and POST; operators must inspect the returned outcome.
            latest = github.request("/actions/runs/" + str(candidate["id"]))
            if (latest.get("status") != "queued"
                    or latest.get("head_sha") != candidate["sha"]
                    or latest.get("event") != candidate["event"]):
                skipped.append(candidate["id"])
                continue
            github.request("/actions/runs/" + str(candidate["id"]) + "/cancel",
                           method="POST")
            cancelled.append(candidate["id"])
        except (HTTPError, URLError, TimeoutError, OSError, ValueError) as exc:
            errors.append({"id": candidate["id"], "type": type(exc).__name__,
                           "http_status": getattr(exc, "code", None)})
            break
    return cancelled, skipped, errors


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--apply", action="store_true",
                   help="Explicitly cancel only revalidated superseded queued runs")
    p.add_argument("--summary-only", action="store_true",
                   help="Display aggregate reasons and a short sample, not all queued run IDs")
    p.add_argument("--max-cancel", type=int, default=10)
    p.add_argument("--page-limit", type=int, default=MAX_PAGE_COUNT)
    args = p.parse_args(argv)
    if args.page_limit < 1 or args.page_limit > MAX_PAGE_COUNT:
        p.error(f"--page-limit must be within 1..{MAX_PAGE_COUNT}")
    try:
        token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
        if args.apply and not token:
            raise PermissionError("No Actions-write token; refusing --apply")
        github = Github(token=token)
        report = inspect(github, args.page_limit)
        if args.apply:
            cancelled, skipped, errors = cancel_candidates(
                github, report, args.max_cancel)
            report["cancellations_executed"] = len(cancelled)
            report["cancelled_ids"] = cancelled
            report["skipped_on_recheck"] = skipped
            report["cancellation_errors"] = errors
        if args.summary_only:
            report["candidate_reasons"] = dict(sorted(Counter(
                item["reason"] for item in report["candidates"]).items()))
            report["sample_candidates"] = report["candidates"][:5]
            del report["candidates"]
        print(json.dumps(report, indent=2, sort_keys=True))
        return 3 if args.apply and report.get("cancellation_errors") else 0
    except (HTTPError, URLError, TimeoutError, ValueError, KeyError,
            PermissionError, OSError) as exc:
        print(json.dumps({"error": type(exc).__name__ + ": " + str(exc),
                          "cancellations_executed": 0}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
