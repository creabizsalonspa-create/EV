# Native administrator campaign moderation

The native account menu now exposes Campaign administration only for
Admin. It uses the existing authenticated commercial campaign list,
detail and revision-checked moderation endpoints. A vendor/customer
cannot use this screen to inspect other vendors' campaigns.

The native workspace presents exact campaign identity, immutable paid
package and station source IDs, both languages of creative, target
country/region/placement, dates, reserved and delivered units, verified
server click count, current serving eligibility, and immutable control
history. It validates positive exact IDs and control revisions (including
the API's canonical decimal-string revision), bounded integer delivery
metrics, page counts, matching immutable terms, audit lineage and last
event/revision/state. Paid-package units are not additional cash or
evidence of human impressions.

Admin may approve/reject submitted campaigns, suspend approved campaigns,
and reinstate suspended campaigns while the adapter is enabled. Each
decision requires a reviewed reason and a separate explicit confirmation,
then rechecks the current Admin account before dispatching the exact
displayed revision. The canonical backend independently authorizes the
role, locks the source and validates state and paid entitlements. The
client accepts only the intended campaign, unchanged immutable terms,
expected resulting state and advanced control revision. A failed or
uncertain response clears the review and requires fresh authoritative
history. No automatic retry or new payment occurs, and approval alone
never implies current serving eligibility.

Session events, API replacement and background transitions erase private
records and fence delayed reads and old confirmation dialogs; foreground
requires explicit refresh. The tests cover typed source and audit
validation, explicit moderation, stale role, forged acknowledgement,
revoked dialog, delayed response and Arabic disabled-state privacy.

This adds partial native software parity for 3.7.7 / 4.4.5 / 7.1.9 /
7.1.10. It does not implement standalone cash ad orders, new advertising
tax rules, external publisher deployment, human impression proof, or
live money. Exact-head hosted validation and Agent 5 integration remain
required. Preserve independent main.dart menu changes, notably #321
native finance queue, rather than replacing the entire navigation file.
