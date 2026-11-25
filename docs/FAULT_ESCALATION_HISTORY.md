# Assigned simulator fault escalation history

The existing cookie-authenticated Admin operational health endpoint includes `fault_escalations`, a read-only projection of durable simulator escalation receipts assigned to the current authenticated administrator. Other administrator assignments, customer or vendor identities, source payloads, and credentials are not included. The current active admin role and captured credentials are rechecked under lock by the parent endpoint.

A page contains at most 25 receipts ordered by descending alert ID. IDs and `next_before` are decimal strings to preserve exact identity beyond JavaScript's safe integer range. GET `/api/admin/operational-health?fault_before=<next_before>` reads the next exclusive older page. Omit the cursor to refresh the latest page. Unreadable storage returns an explicit unavailable state, not a claim that no incidents exist.

`recorded_open` is a durable opening receipt. `recorded_closed` is a chronologically consistent historical receipt with either `operator_acknowledged` or `source_recovered` as its recorded closure reason. Contradictory dates, unknown reasons or malformed receipt fields are projected as `requires_review`. These are not assertions about a charger's current fault status, capacity, physical repair, hardware validation or production readiness. No mutation or automatic retry is exposed.

The EN/AR Admin operational-health page renders the same receipt distinctions and offers explicit older/latest pagination. It clears private data when hidden, on page/account change or when a response is invalid. No new native admin authorization or production device operation is introduced. Full original incident lifecycle scope remains incomplete pending hosted validation and remaining workflows.
