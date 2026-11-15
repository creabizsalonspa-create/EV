# Finance Approval Queue

GET /finance/approval-queue is an authenticated Admin-only read-only aggregation of three canonical financial review sources: pending customer refund requests joined to their invoice, pending vendor settlement requests, and vendor adjustments without recorded approval. Records carry kind, source ID, vendor, invoice ID where applicable, currency, minor precision where available, signed minor amount, status, creation time. No customer identity, bank/provider references, private evidence, reason, or request keys are exposed.

Query filters: kind=all|refund|settlement|adjustment; strict uppercase three-letter currency; positive vendor_id of 1-18 digits; page 1-10000; per_page 1-25. SQL UNION ALL paginated by date, ID and kind; no entire-history PHP materialization. Inherits both cookie and native authorization boundaries.

No money is moved or posted. No approval, refund, dispute resolution, hold release or payout operation is performed. Does not grant authority to approve. This is a simulated-only review aid, partial toward requirement 4.3.9; production and full mobile/Arabic supervisor experiences remain outstanding.
