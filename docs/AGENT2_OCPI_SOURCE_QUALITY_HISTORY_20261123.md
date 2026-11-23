# Agent 2 — Stored OCPI catalog quality history (23 November 2026)

This is a scoped **software implementation toward original PRD atom 6.2.4**, not an assertion that network coverage is complete, production accepted, partner certified, or that a charger is available.

## What changed

Each successful **changed, normalized locations source batch** and each optional **read-only hourly observation** now records a canonical provider-scoped quality observation within the same database transaction as OCPI ingestion. It samples only the existing stored timestamp-age policy via `PartnerCatalogHealth`; it performs no external provider request, GPS collection, charger control, reservation, payment or settlement. An identical replay produces no sample. A conflicting batch rolls back both source and quality history. The underlying owner/provider scope remains protected by Agent 2's parent provider-revocation PR.

Observations record only the UTC hour and last sample time, configured freshness-policy age, published-record count, within-policy count, stale count, unknown clock/receipt count and future-source-clock count. No coordinates, addresses, payment tokens, user identities, raw OCPI data, request bodies, or individual charger records enter the quality-history table.

The `ocpi_catalog_quality_history` table uses a per-provider/per-UTC-hour unique key. A changed batch within the same hour updates that hour's observed counters instead of fabricating a new hourly point. Retention is bounded to seven days and 168 hourly entries per provider, and the private report exposes at most 30 newest samples. Older-than-seven-day samples are excluded from reads even if no subsequent sync has occurred.

A provider whose freshness policy is unconfigured produces `policy_unavailable` with null quality counts; it never invents a green/fresh ratio. Snapshots and report reads strictly reject contradictory accounting. Future-clock records remain within the unknown age bucket, not within available/recent inventory. Zero recorded samples is not proof of no stations or complete coverage.

## Endpoints and clients

`GET /api/ocpi/providers/{provider}/catalog-quality-history` and the same `/api/v1/ocpi/providers/{provider}/catalog-quality-history` require active vendor/admin authentication and current tenant/role/epoch/password authority. They never query live partners and return `Cache-Control: private, no-store`.

The existing stored catalog observations workspace adds a separate **Read source quality history** control in English and Arabic on both the web and the canonical Flutter application. It does not request quality data until explicitly invoked. It rejects source-identity mismatches, changed authority flags, impossible/contradictory counts, malformed/duplicate/out-of-order UTC hour samples and unexpected live/financial claims. The view clears private output on account, route and app lifecycle transitions.

## Validation and dependency

Source branch: `agent2/ocpi-catalog-quality-history-20261123`. Base dependency is Agent 2 PR #232, which owns the tenant/source revocation fence. Agent 5 is the only canonical integration and merge controller.

Applicable checks after integration are Laravel `OcpiCatalogQualityHistoryTest` on SQLite and PostgreSQL; existing OCPI catalog/ingestion regression; the EN/AR Chromium `tests/partner-catalog-observations.cjs`; Flutter `test/ocpi_catalog_observations_test.dart` plus full source/native/device CI; and SQL data-preserving migration acceptance. No hosted exact-head or canonical merge acceptance may be inferred from source presence.

**Bounded physical cleanup:** In addition to the seven-day read filter and per-provider cleanup on changed ingests, `php artisan ocpi:expire-source-quality-snapshots --limit=500` deletes UTC-hour observations past the 168-hour retention boundary when a provider has stopped syncing. This is an hourly, globally bounded database-only cleanup, even when optional sampling is disabled; it does not erase canonical OCPI records or send any external request. Rows that were updated to a nonexpired hour between selection and deletion are rechecked against the retention cutoff. Tests cover idle-provider expiry, reject zero limits, idempotent reruns and oldest-first one-row batches that retain current samples. The cleanup scheduler must be operated on an installed, migrated instance; no production migration or cleanup has been executed as part of this PR.

External gaps are still explicit: complete physical network coverage, operator feed licensing/SLA, real station freshness, conformant live roaming, actual operator settlements and app-store/production release acceptance. The aggregate original 42-category/312-atom inventory remains unchanged until integrated and verified evidence warrants stage credit.

## Offline hourly aging without changed syncs

An additional opt-in server-only `OCPI_CATALOG_QUALITY_SAMPLING_ENABLED` gate controls `php artisan ocpi:sample-source-quality --limit=25`, scheduled every minute with a process overlap lock but disabled by default. It never dispatches OCPI requests or charger/payment commands. Each invocation samples at most 50 approved, active vendor-owned providers with existing normalized location data that lack a sample for the current UTC hour. It acquires the current user/provider locks, revalidates ownership, and records only the existing normalized quality snapshot. Previously sampled providers are omitted from the next bounded work queue until the next hour, preventing high-ID starvation. A separately changed location batch may refresh its same-hour observation. Unit tests cover stale aging without a new provider fetch, schedule opt-in, bounds, suspended owners, tenant reassignment, and idempotent batch progress. A successful cron process is not proof that a real network feed is healthy or that a charging station is online.
