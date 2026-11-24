# Current vendor and exact settlement governance for TEST payouts

The existing payout service now rechecks the locked vendor's persisted role
and active account status before a new payout is prepared, approved,
reprepared after known failure, or executed. The same preflight runs at
the durable request-sent boundary immediately before TEST provider dispatch.
It rejects inactive or demoted vendors without starting a new provider
request. Already-sent provider facts can still be reconciled; authorized
supervisory cancellation remains available for the existing safely
cancellable statuses. No real payout is enabled.

The payout eligibility check reuses SettlementService's exact signed
minor-unit reconciliation instead of a lossy sum/cast. It requires a
nonempty positive settlement and verifies positive invoice source lines
match the vendor, currency and precision of the original settlement.
Legacy settlement approval and simulated payout now require the same
nonempty, exactly reconciled reservation. Rejection remains possible
without rewriting the financial source. Private transition responses
omit internal request identity and carry private, no-store cache control.

VendorPayoutTest exercises suspended/demoted vendor preparation,
approval and dispatch, zero new attempts and safe cancellation.
SettlementTest exercises a synthetic unsupported historical reservation,
blocked approval, rejection, preserved cash journals and redacted private
transition responses. Existing exact source, provider uncertainty and
PostgreSQL concurrency regressions must remain intact. No immutable
trigger is bypassed. Hosted CI must pass at the exact source head before
Agent 5 integration. This is a partial TEST governance improvement, not
a completed original requirement or production financial acceptance.
