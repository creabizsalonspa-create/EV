# Customer noncash coupon activation and receipt provenance

This continuation consumes Agent3's canonical policy/receipts (`638d5222`, preserving `8e837770` and referral/coupon history). It adds no eligibility, points, financial accounting or fulfillment rules.

Customer Flutter and web coupon interfaces load the authenticated `commercial/noncash/status` capability. Disabled claiming retains explicit private receipt inspection and permanent cancellation of an uncertain intent. Reviewed manual capability never enables positive-point controls here or asserts money movement/delivery. Existing guarded local Sandbox setup remains unchanged; no production activation was performed.

Each receipt validates its own immutable activation evidence. A recorded reviewed-manual receipt is labeled accordingly; legacy test receipts remain Sandbox records, even if current configuration changes. Unknown/unrecorded reviewed provenance fails closed and retains the original recovery identity. Request binding, encrypted native persistence, browser binding-only storage, exact explicit retries, durable cancellation and lifecycle/account isolation are preserved.

The native fixture now invokes canonical publication inside an explicit database transaction, matching its normal route context. This changes fixture execution only; financial source-of-truth code is unchanged.

Validation: 18 focused Flutter cases; 24 controlled EN/AR390 browser checks; one actual Laravel native case and 14 actual Laravel EN/AR390 browser checks; 31 Laravel policy/coupon/referral cases (228 assertions); full Flutter analyzer clean; financial CI canaries and fixture Pint pass. Actual connected tests ran sequentially and verified canonical financial storage was unchanged by coupon claiming/recovery/cancellation. The full Flutter suite passed 828 cases with 40 explicit opt-in skips; hashed logs are recorded in the sibling evidence manifest.

Reviewed-manual examples are controlled client/backend policy tests. Actual connected local tests use Sandbox. No reviewed production programme was activated; no physical fulfillment, live payment, store signing, real-device or production acceptance is claimed. Newer external PR194 opted-in coupon-discovery changes are not included in this candidate and require coordinated reconciliation with the durable recovery/provenance contract. Customer referral consumer journeys remain a separate unfinished batch; Agent3 owns their backend/Admin source of truth. Agent5 controls integration, hosted Android/iOS verification and requirement credit.
