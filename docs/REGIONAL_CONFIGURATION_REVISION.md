# Regional configuration revision boundary

Partial PRD 4.6.5 / 4.6.6. This is configuration safety, not country-specific legal, tax or live-payment approval.

- Existing regional settings start at revision 1. A new country is created only with expected revision 0 (omission is the legacy create-only contract).
- Updating an existing region requires its exact current revision. Replaying a stale revision, including an absent-country revision, returns 409 without changing settings or adding an audit entry.
- Admin account authority and the existing region row are locked inside the canonical transaction. Concurrent creation uses insert-or-ignore with the unique country identity rather than an overwrite.
- Identical settings at the current revision are a no-op. A real change advances the revision and records the established region.configured audit event.
- Currency is exactly three uppercase ASCII letters; this validates syntax, not an approved ISO currency or country/currency combination. Time zone, precision and tax retain the existing validation contract. Zero tax is distinct from unknown tax.
- Review-intake enablement and its independent revision are preserved. Commerce is always disabled by this endpoint. Neither this control nor the feedback gate authorizes live financial or charger operations.
- The web editor explicitly loads the selected country and revision into the form, binds edits to the current account epoch, and requires a fresh selection after a conflict. It never automatically retries a stale edit.
- Migration rollback refuses to discard advanced configuration revisions outside tests.

Regression tests cover stale create/update, exact zero/null, no-op audit, independent review gate and unchanged other country. Full Laravel/PostgreSQL and browser CI required. No country-specific deployment certification, production activation or whole-atom credit is claimed.
