# Native refund request acknowledgement and private lifecycle

Original scope: 2.8.7, 3.5.6 and 7.1.10 (partial software).

The pending #248 private refund DTO deliberately removes raw replay keys
from API records. The existing Flutter request flow required an echoed
request_key before acknowledging its durable encrypted intent. Combining
those sources unchanged would leave every successful customer refund request
in a false uncertain state, even when the original request already existed.

This coordinated backend/client change preserves the private DTO for every
history/review response. Only the authenticated original POST or exact
payload-bound replay returns request_receipt, the SHA-256 binding of
refund-request-v1:customer_id:invoice_id:request_id:original_request_key.
It is not a signature, payment authority, provider verification, or
authentication credential. It is a correlation value for the already
authenticated response, not accepted as an input to any endpoint. The
original replay key remains server-owned and the binding is never included
in list or reviewer DTOs. Native code verifies the exact owner/invoice,
positive integer record ID, amount, reason, source credit, status and
receipt binding before clearing encrypted durable request storage.
Old clients that require a raw key must be upgraded alongside the API.

Refund request and vendor review screens capture their opening session
generation, snapshot the exact consent payload, check authority after
dialog and storage awaits, and invalidate on background or session change.
Reentry restores an existing saved request without automatic dispatch.
Private list records are removed on background and session invalidation.
The existing #265 durable acknowledgement fence is carried byte-for-byte.
All new provider financial movement remains subject to existing TEST gates.

Laravel CommercialFinanceTest and Flutter refund receipt/session tests
exercise initial/replay binding, changed payload denial, distinct keys,
history/reviewer privacy, exact receipt correlation, customer/vendor
revocation while consent is open and foreground history reload.
Existing refund/provider/ledger tests must pass on the exact head.
No full requirement or production acceptance is asserted.

Integration dependencies: this branch carries the complete #248
CommercialFinanceController.php and CommercialFinanceTest.php changes
plus the new binding. The old canonical blob IDs exactly matched #248's
diff preimages before replacement. Preserve #248's other finance sources.
financial_operation_store.dart is byte-identical to #265's latest version.
The shared native_financial_operations_test.dart edits must be combined
with #265 and #272's independent tests and corrected document fixtures.
Agent 5 alone integrates after exact-head validation.
