# Finance Approval Queue

GET /finance/approval-queue is an authenticated Admin-only read-only aggregation of three canonical financial review sources: pending customer refund requests joined to their invoice, pending vendor settlement requests, and vendor adjustments without recorded approval. Records carry kind, source ID, vendor, invoice ID where applicable, currency, minor precision captured from each immutable invoice, settlement or adjustment, signed minor amount, status, creation time. No customer identity, bank/provider references, private evidence, reason, or request keys are exposed.

Query filters: kind=all|refund|settlement|adjustment; strict uppercase three-letter currency; positive vendor_id of 1-18 digits; page 1-10000; per_page 1-25. SQL UNION ALL paginated by date, ID and kind; no entire-history PHP materialization. Inherits both cookie and native authorization boundaries.

No money is moved or posted. No approval, refund, dispute resolution, hold release or payout operation is performed. Does not grant authority to approve. This is a simulated-only review aid, partial toward requirement 4.3.9; production and full mobile/Arabic supervisor experiences remain outstanding.

## English/Arabic web review and tests

The authenticated commercial-finance workspace adds an Admin-only, lazy "Load pending finance reviews" control. It renders separate refund, settlement and adjustment entries with minor-unit amounts and explicit simulator-only/never-payout labels. It offers no approval/refund/payout action. A kind selector and bounded previous/next pagination never mutate the original workflow. The UI clears private rows on filter changes, tab hiding, account changes, and discards stale in-flight responses.

FinanceApprovalQueueTest exercises true canonical source rows, negative pending adjustments, approved adjustments excluded, status-specific kinds, currency/vendor/pagination bounds, customer/vendor rejection, disallowed Admin personal access tokens and no new journal or settlement events. The real-Laravel financial analytics browser test confirms that a customer has no queue button and Admin can load a read-only bounded queue without mutation controls.
