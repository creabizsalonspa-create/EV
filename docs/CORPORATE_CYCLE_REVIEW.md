# Corporate monthly billing review

The existing immutable corporate agreement has a billing_day but manual
consolidation did not surface a scheduled review. The new authenticated
GET commercial/corporate/agreements/{agreement}/cycle-review uses the
previous completed application-calendar month and the configured billing
day (1–28) of the current month. It uses the same invoice creation-month
interpretation as the existing issue workflow; this is not an approved
jurisdiction-specific tax period or a new agreement term.

Only the current fleet owner or current financial administrator may read
the review. The live session/role and fleet row are checked within the
read transaction. Approved unallocated claim records from the completed
month are read against original invoice components and currency/precision.
The view reports the complete proposed amount, remaining same-currency
agreement credit and original invoice financial holds. If over 100 rows
exist, the endpoint returns a lower-bound count of 101 and no partial
selection or misleading total. A held, inactive, early, empty or
over-credit batch is explicitly distinguished from ready_for_review.

The bilingual browser company workspace exposes the review and an explicit
link to the existing manual claim selection screen. No statement is
issued by previewing, no payment is captured, and no automated liability,
credit underwriting, subscription, email or schedule is created. The
existing issue endpoint remains the only authority; its locks and
validation recheck the sources at actual issuance. The preview is not
a reservation or assurance that another actor cannot change eligibility.
Past months outside this one-cycle preview still use manual selection.

Tests: CorporateCycleReviewTest uses real synthetic fleet agreements,
approved invoice claims, before/at billing-day boundaries, current
owner/Admin scope, remaining credit, partial payment holds and already
consolidated claims. Disposable PostgreSQL finance filter includes the
new class. Existing actual Laravel/Playwright corporate billing test
opens the new manager review, verifies empty previous-month state and
the absence of the manager control for a driver, then exercises the
original issuance and mock collection flow. Hosted execution is required
before integration; tests supplied are not claimed passed.

This is partial corporate cycle planning software (7.1.7 / 8.1.6 / 8.1.7).
Native review UI, explicit agreed automatic issuance policy and worker,
legal acceptance, notifications, and live collection remain distinct.
