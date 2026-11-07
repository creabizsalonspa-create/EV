# Internal payment-case evidence

This extends the existing `gateway_cases` dispute workflow. It does not manufacture PSP/card-network disputes, submit files to Stripe, release a settlement hold, resolve a case or post a refund. Existing provider dispute ingestion/text evidence and canonical financial decisions remain separate.

## API

Authenticated cookie/token clients share:

- `GET commercial/cases/{case}/evidence?page=N`: paginated allowlisted metadata and authoritative `can_upload`.
- `POST commercial/cases/{case}/evidence`: multipart `kind` (`service_record`, `receipt`, `correspondence`, `other`), explicit accepted `consent`, `idempotency_key` and `document`.
- `GET commercial/cases/{case}/evidence/{evidence}`: authorized private attachment download.

Only the source invoice customer, its vendor and Admin may read/upload. Foreign identities and mismatched case paths return404. New uploads require an open case; exact committed replay remains available after resolution. The actor/case-scoped key binds category, sniffed MIME, size and SHA256. Changed payload conflicts. Each case accepts at most20 files; PDF/PNG/JPEG signatures and server-sniffed types are bounded to4 MiB.

Files use the private local disk, random paths and Laravel authenticated encryption. Original names are discarded. Metadata is immutable in SQLite/PostgreSQL; populated downgrade is blocked. Lock order matches canonical case resolution (invoice then case), so resolution cannot race a new append after closing. Downloads verify decrypted byte length and SHA256 and set private/no-store, nosniff, attachment and sandbox headers. Unknown DB commit outcomes preserve ciphertext rather than deleting possibly committed evidence. Encrypted orphan inspection/retention operations remain part of deployment readiness.

Public records contain only id, case_id, uploaded_by, kind, MIME, size, hash and timestamp plus `evidence_status=PRIVATE_REVIEW_ONLY` and `provider_submitted=false`; no storage paths, request fingerprints or private provider identities.

## Interfaces and recovery

The commercial web workspace includes a customer/vendor/Admin private review panel, consented multipart upload and bounded hash-checked download, literal text rendering, EN/AR, themes and session fences. The shared session client retains CSRF/cookie scoping and JSON behavior while allowing browser-generated multipart boundaries and rejecting upload redirects. The component keeps an unconfirmed upload's original key/fingerprint across its own refresh; it does not persist file contents or claim durable browser-restart recovery.

Flutter adds scoped paginated case history, invoice-context dispute creation with exact Arabic/Persian monetary parsing, and evidence review/upload. Native actions persist encrypted owner/origin/source-scoped intent metadata before dispatch. Files remain in memory; after restart the original file must be reselected and match its frozen hash/size/MIME/category. Different bytes cannot replace an unknown upload. No automatic POST occurs on refresh/return. Binary snapshots freeze before async secure storage; acknowledgement must match the exact fingerprint before clearing it. Private preview reuses the bounded PDF/image viewer after MIME/signature/hash verification. Closed cases hide new-upload controls but retain private history and committed replay.

Actual Laravel browser/native and backend tests distinguish internal records from provider submission and confirm no payment/refund/journal changes. iOS runtime adds synthetic consented multipart upload with actual secure intent storage while retaining all earlier assertions. Hosted validation is required for the candidate; local tests do not prove physical device, live PSP, legal or store acceptance.

## Remaining Batch A

Complete hosted charging/subscription checkout and SCA/return recovery, native payment confirmation, provider attachment submission/response history, payout exception/return recovery and remaining financial deep links. Existing private evidence collection is not complete card-network chargeback handling. The fixed42/312 tracker receives no broad native parity/provider-submission credit for this subset.
