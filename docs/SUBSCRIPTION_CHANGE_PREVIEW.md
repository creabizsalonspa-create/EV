# Read-only scheduled subscription plan-change preview

GET /commercial/subscriptions/{subscription}/change-preview?plan_id={target} is mounted inside the existing authenticated /api session and /api/v1 bearer groups. It is available to the owning customer/vendor and authorized administrators; foreign subscriptions return 404. Invalid target IDs are 422. The target must be enrollable and match owner audience, currency and precision; the current subscription must be active, still in its paid cycle and not scheduled to cancel. Invalid transitions return 409. The exact policy is shared with the existing change_plan mutation to avoid quote/action drift.

The response reuses the immutable target plan version and integer Money::multiplyDivide tax calculation applied to the next cycle invoice. It provides current/proposed IDs, plan version, currency/precision, target cadence, existing cycle end in UTC, next-cycle subtotal/tax/total in minor units, and explicit simulation flags.

It never creates or reserves an invoice, payment, credit, upgrade, entitlement or proration. Flags amount_due_now_minor=0, proration_supported=false, quote_is_binding=false and real_payment_processed=false disclose that the preview is informative, nonbinding, and simulated. The later change_plan mutation rechecks eligibility.

SubscriptionChangePreviewTest covers exact rounding (2,999 minor × 500 bps => 150 minor tax), current paid invoice and entitlements, zero financial mutations, foreign access, malformed input, retired plan, expiry and normal scheduled plan change. This is a bounded finance UX/data-contract addition, not mid-cycle proration, live PSP payment, legal tax certification or complete platform acceptance.
