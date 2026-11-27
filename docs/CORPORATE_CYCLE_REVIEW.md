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
issued by previewing and no payment is captured. The canonical issue
service remains the only statement-writing authority; its locks and
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
The native agreement workspace also exposes the bilingual monthly review
for managers. It rechecks the current account and server can_manage
authority before each review, validates the complete immutable source
batch, exact integer totals, currency/precision and readiness state, and
clears private results on account invalidation/background. It requires
an explicit foreground refresh. Reading or refreshing never dispatches
a mutation.
The root agreement menu invalidates its opening session on change,
preventing stale navigation from carrying earlier company context.
Flutter regression tests cover parser rejection, real menu navigation,
manager revocation, delayed responses and Arabic foreground privacy.

Explicit agreed automatic issuance policy and worker, legal acceptance,
notifications, and live collection remain distinct.


## Explicit reviewed TEST batch issuance

Both web and Flutter expose a separate confirmation action only when the
server returns can_issue_reviewed_batch=true. This is limited to the
current customer fleet owner in local/testing with the mock payment
provider. Administrators may still review but cannot consent for an owner
through this additional endpoint. No live payment, scheduler or automatic
statement issuance is enabled.

POST commercial/corporate/agreements/{agreement}/cycle-review/issue
accepts the exact reviewed period, sorted claim IDs, positive exact
total_minor, and explicit JSON consent=true. The same current account
and fleet locks protect the fresh complete review. Changed period, batch,
credit eligibility, source holds, total, billing day or ownership prevent
issuance. The existing CorporateBilling::issue service performs canonical
invoice checks and immutable statement/line creation. The outer transaction
also verifies the resulting scope, owner, amount and exact claim set.

The server derives a domain-separated stable identity from agreement,
period, sorted claim IDs and amount under the current actor. A same-batch
replay returns the original statement ID even after the review has become
empty or the month rolled over, without creating another statement.
Replays require current authorization and the local TEST gate. The
acknowledgement contains no raw request keys, private fingerprints or
provider identity. Both clients verify exact identity, amount, currency,
precision and claim set before showing confirmation. Unknown results never
automatically retry; check statement history or explicitly retry the same
displayed batch. Native session/lifecycle invalidation also fences the
confirmation dialog and delayed response.

Feature regressions exercise denial before billing day, administrator/
driver denial, wrong amount/selection/month, absent consent, provider
gate, newly held source, exact replay and unchanged cash journal/receipt
counts. Flutter tests cover cancelled consent, malformed acknowledgement,
exact explicit repeat and revoked session while confirmation is open.
The prior read-only regression remains. Hosted validation is still required.
