# Admin KYC license-date watchlist

This Agent 7 candidate extends the **existing** authenticated Admin KYC queue and
VendorOps review workflow. It is a date-based triage aid, **not** a legal
verification, KYC certification, station-safety approval or physical-charger
authorization. No submitted/approved company is automatically suspended.

## Admin web and API

The existing `GET /api/admin/kyc-queue` remains status-scoped and paginated
(25 per page). Its default is `status=submitted`. Admins can combine
`status=approved`, optional `country=AE` or `country=CA`, and optional
`license_health`:

- `expired`: expires **before** today's date in the company's configured country timezone.
- `due_30_days`: expires today through 30 local calendar days ahead, inclusive.
- `current`: expires **after** that 30-day window.
- `unknown`: missing license date or missing/invalid configured country timezone.

Without the health filter, the existing queue ordering and status behavior are
preserved. Every row reports the above `license_health`, the country's
`license_local_date` (null when unconfigured), and the whitelisted
`license_expires_on`. Filters execute **before** pagination, preventing missed
cases on later pages. The Admin browser filters provide matching labels.
Only existing Admins can see the private queue; requests are bounded, audited,
and `Cache-Control: no-store, private`. Stored trade-license numbers, private
notes, encrypted document bytes and storage paths are not included.

The date boundary intentionally matches `VendorAccess::licenseValid`: a
license expiring today remains valid through the **end of its local day**.
A due/unknown date must not be treated as legal verification or an authorization
decision. Reviewers still inspect the original encrypted KYC evidence and use
the existing audited `POST /api/vendor-ops/companies/{company}/review` action.

## Admin authority at review commit

The KYC review transaction now locks and rechecks the current administrator
row **before** locking the applicant company or its owner. A suspended/demoted
Admin fails with 403; a reviewer whose credentials or account authorization
version changed after the authenticated snapshot fails with 409. No company
status or review history is written on these denied attempts. Existing
independent-review, encrypted-document integrity, verified owner, license,
and allowed-transition rules remain enforced.

## Verification and release boundaries

Targeted tests: `php artisan test --filter=AdminKycQueueTest` and
`php artisan test --filter=VendorOpsTest`; run the complete Laravel/
PostgreSQL concurrency/web browser CI gates before Agent 4 merges. The
watchlist tests freeze a single UTC instant at which Dubai is on 16 November
and Toronto remains on 15 November to check country-specific date semantics,
authorization, absence of accidental writes, and audit privacy.

No production acceptance, license registry integration, KYC authenticity,
country-specific legal clearance, physical charger activation, real-user browser
acceptance, or regulatory certification is claimed from this software slice.
The original fixed 312-atom coverage inventory is unchanged.

## Strict regional calendar provenance (Agent 3 continuation)

A blank or unrecognized region timezone must not cause a date library to fall back silently to the server's default timezone. The KYC license queue now accepts only explicit supported IANA timezone identifiers before generating country-local `today` and 30-day calendars. Unconfigured or invalid timezone countries are classified as `license_health=unknown`, never `current`, `due_30_days`, or `expired`, so human reviewers do not accidentally certify a license based on an unrelated server date. Added tests use blank and invalid timezone values, preserving the private Admin queue and zero automatic vendor approval. This is a source-data quality safeguard, not official license authentication or regulator approval; exact-source PostgreSQL/browser/CI and Agent 5 integration remain required.
