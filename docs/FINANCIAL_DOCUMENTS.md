# Private financial documents

This additive module generates actual PDF and CSV files from the existing immutable charging invoice, charging credit, subscription invoice, subscription credit, vendor settlement and consolidated corporate statement records. It does not calculate new taxes, post payments or replace the canonical invoice number. Generated files are explicitly **TEST_DOCUMENT_NOT_LEGALLY_APPROVED** until an independently reviewed country-specific document implementation is available. Administrator-disclosed issuer data is not legal verification.

## Contracts

All routes in `routes/financial_documents.php` belong inside the existing authenticated, throttled cookie/token API groups. Amounts are integers in the source currency's `minor_units`; zero through three decimal currencies are supported without float conversion.

| Method and path | Behavior |
| --- | --- |
| GET `financial/profiles` | Admin-only, paginated issuer versions |
| POST `financial/profiles` | Admin publishes an immutable issuer version |
| GET `financial/documents` | Scoped paginated metadata; optional `source_kind` and `source_id` filters |
| POST `financial/documents` | Generate or replay a source/locale/template document |
| GET `financial/documents/{id}` | Scoped metadata plus verified decrypted snapshot |
| GET `financial/documents/{id}/download/pdf` | Scoped actual PDF attachment |
| GET `financial/documents/{id}/download/csv` | Scoped UTF-8 BOM CSV attachment |

Profile publication requires `country`, `currency`, `minor_units`, `legal_name`, `legal_name_ar`, `address`, `address_ar`, `effective_from` and actor-scoped `idempotency_key`. `vendor_id` is nullable for the platform issuer; `tax_registration` is optional. Country/currency/precision must match a configured region. Changed-payload key reuse returns409. Versions serialize on the region lock and never rewrite prior disclosures. The effective issuer must predate the source issue time.

Generation accepts `{source_kind, source_id, locale}` where source kind is `charging_invoice`, `charging_credit`, `subscription_invoice`, `subscription_credit`, `vendor_settlement` or `corporate_statement`, and locale is `en` or `ar`. Natural idempotency uses source kind/ID/locale/template version. Settlement and corporate statements additionally version the immutable document by source state; later generation after a state transition produces a new document while preserving earlier bytes. Metadata exposes `current_source_state` separately from the PDF state at generation. No statement claims real bank proof. Charging documents require the matching vendor/country issuer. Existing subscription invoices do not contain a legal country: exactly one applicable platform issuer jurisdiction for the invoice currency is required; ambiguity returns409 rather than inferring a buyer country from locale or device location.

Customers see their own invoice documents, vendors see their issued charging documents, own subscription documents and own settlement statements, and financial administrators may access all. Corporate statement generation, listing, reading and downloads check the **current fleet owner** or administrator; drivers and former owners do not gain company-wide document access. A transfer removes the former owner's access even to already generated PDFs. Corporate jurisdiction comes from the immutable underlying invoice country, not a mutable device or fleet location. Foreign document reads and downloads return404. Responses exclude private paths, encrypted payloads and publishing request hashes. Downloads use `private, no-store`, `nosniff`, attachment disposition and `X-Document-SHA256`.

## Storage and integrity

Migration `2026_10_28_190001` adds issuer versions, source-document snapshots and per-issuer/year sequences. The additive `CB-TDOC-*` number never replaces the source invoice/credit number. Database triggers reject update/delete of posted document/profile rows. A populated downgrade outside disposable tests refuses to destroy history.

PDF/CSV bytes and source snapshots are encrypted with Laravel's application key. Files live only in `storage/app/private/financial-documents`; no public filesystem URL or user-selected file path is exposed. SHA256 checks cover decrypted snapshot, PDF and CSV. Missing or corrupted artifacts return503 and are never silently regenerated under a historical number. Back up the private files, database and encrypted key recovery material together. Key rotation and restore require an explicit operational procedure; this module does not claim a tested production recovery deployment.

Source rows are locked before generation, and a locked per-issuer sequence prevents overlapping generation from allocating duplicate references. Generation produces no financial postings. CSV values defend against spreadsheet formulas after leading controls, whitespace and bidi controls. PDF HTML comes exclusively from an escaped static server template: no user HTML, image URL, JavaScript, attachment or font path is accepted.

## Renderer and licenses

`composer.lock` pins `tecnickcom/tcpdf7.0.20`, its compatibility facade over modern `tecnickcom/tc-lib-pdf8.82.2`, plus its versioned dependencies. Composer's semver declaration permits safe maintenance updates only through an explicitly reviewed lock change. These15 Tecnick packages declare LGPL-3.0-or-later; preserve their distribution license/source notices and applicable redistribution obligations when packaging dependencies. They remain separate Composer packages, with upstream source references in the lock, rather than copied proprietary application code. `composer audit` reported no known advisories during local verification; that is not a security certification.

The engine ships without fonts. This module vendors compiled DejaVu Sans regular/bold assets, the Bitstream/DejaVu license, upstream/package provenance and SHA256 checksums in `resources/financial-fonts`. No runtime font download or system font dependency is required. Both Arabic glyph shaping and embedded Unicode PDF fonts were checked with real generated artifacts; Arabic and English remain distinct immutable documents.

## Verification and remaining requirements

`FinancialDocumentsTest` covers six source families, tenant isolation, issuer authorization/effective dates/idempotency, encrypted private storage, real PDF/CSV signatures, immutable history, tamper/missing-file failure, literal HTML/CSV handling and0/3-decimal currency behavior. `FinancialDocumentConcurrencyTest` supplies independent PostgreSQL process races for same-source replay and first issuer-sequence allocation plus vendor/corporate same-source generation without initiating payout or collection; record executed PostgreSQL evidence separately from source availability.

This module does not claim legally certified invoices, e-invoicing submission, jurisdiction approval, automatically issued monthly statements, tax-inclusive tariff policy, or a complete financial reporting product. Those remain separately tracked financial requirements. External legal/finance approval must supply country-specific required issuer/buyer fields, numbering, language, retention and tax treatment before real issuance.

Vendor statements contain signed invoice/adjustment allocations and preserve any negative carry-forward lines. Corporate statements include underlying invoice references, cost-center allocations, the billing month, due date and source tax totals. These consolidate source records without issuing a second legal invoice or recalculating historical taxes. Printable PDFs retain a fixed print palette; application themes apply to the web controls, not to historical document bytes.
