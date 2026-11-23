# TEST financial documents — current-account private source and encrypted artifact authority

**Agent 3, original 2.8.9 / 4.3.8 / 7.2.7: partial security/readiness slice; not a legally certified tax invoice.**

The existing Laravel financial document subsystem provides number-sequenced,
immutable English/Arabic PDF and UTF-8 CSV TEST artifacts for charging
invoices/credits, subscriptions, vendor settlement statements and corporate
statements. Every generated artifact is source-bound to a specific historical
issuer version and encrypted at rest on a private disk, with a stored SHA-256
for snapshot and individual download bytes. All amounts and sources are
canonical; none is a legally approved tax invoice, bank transfer, card
statement or external PSP receipt. The returned legal status stays exactly
`TEST_DOCUMENT_NOT_LEGALLY_APPROVED`.

## Confidentiality defect addressed

Before this change, `FinancialDocumentsController` used `$request->user()`
from the already-authenticated Laravel snapshot for Admin issuer profile
lists/creation, owned document list/generation/show, and the
`/download/pdf|csv` encrypted artifact fetch. An operator suspended,
removed from Admin, or whose password/auth epoch was rotated could remain
a cached authenticated model and request sensitive invoice recipient
details, merchant issuer tax IDs and exact PDF/CSV bytes until the session
re-authenticated. Service-level `FinancialDocuments::access`,
`::generate` and `FinancialProfiles::publish` similarly accepted
stale User role snapshots.

## Changes

- Admin issuer profile list, publication and all private document queries
  first lock the persisted current account and verify role, status,
  password hash and authentication generation. A former Admin receives
  409 before validating any private issuer/jurisdiction form fields.
  Issuer profile list now explicitly carries `private, no-store`.
- Private document list, metadata and PDF/CSV download materialize their
  results under that account lock. Download content remains byte-for-byte
  encrypted-disk backed, hash-verified, MIME-allowlisted, immutable,
  `nosniff` and not publicly linked. A former customer or vendor cannot
  keep downloading invoice PDFs or changing tax recipient scope with
  cached credentials.
- `FinancialDocuments::access`, `FinancialDocuments::generate` and
  `FinancialProfiles::publish` independently pin persisted User
  identity inside their own DB transactions. This also protects direct
  service callers, not just the HTTP route. Corporate-document access
  still dynamically follows the **current** fleet owner, and a
  different user never inherits the previous user's invoice documents.
- The controller verifies current identity before validating a private
  generation request, but **does not** wrap artifact generation in a
  second outer transaction: `FinancialDocuments::generate` retains its
  original DB/crypto/filesystem transaction, exception catch cleanup and
  immutable numbering, preventing an orphan encrypted file if response
  formatting fails after generation. The service rechecks current
  identity again under its own source/file transaction before creating
  or replaying an artifact.
- No new legal document semantics, regulatory invoice fields, tax
  rules, issuer legal approval, live payment capture or external approval
  assertions were added.

## Regressions

`FinancialDocumentsTest` now creates genuine encrypted signed-source
charging invoice PDF/CSV before revocation and simulates an unchanged
in-memory Laravel authenticated model while the persisted account is
suspended, demoted from Customer/Admin, password-rotated or auth-epoch
replaced. **Every** document list/show, PDF/CSV download and generation
call denies 409. Direct service reads/generation/issuer publication
also deny. After restoring the same user, the original PDF SHA-256
and sequence are unchanged; there is no newly generated numbered
artifact, invoice/payment/journal write, unexpected external HTTP, or
silent private file rewrite. Invalid issuer forms are rejected with
current Admin 422 but stale Admin 409 *before* form validation.
Existing English/Arabic, corporate-owner-transfer, immutable sequence
and file-corruption fail-closed regressions must continue to pass.

This is a code/test handoff, **not a completed atom or production
approval**. Agent 5 integrates only after exact-head Laravel/SQLite
and PostgreSQL, browser/private document, finance and combined platform
checks; legal/regulatory review of real tax invoices is outside software
simulation and remains unsatisfied.


## Native Customer/Vendor private PDF session and foreground parity

The existing `apps/mobile/lib/financial_documents.dart` viewer now
binds its live API session generation to every private list page,
document-generation action and byte download. Previously the user
could sign out during a delayed response and the old screen would
still display an immutable financial document or launch a PDF using
an obsolete record; a backgrounded action also had no explicit native
lifecycle guard.

The native view now:

- Captures the authenticated account generation before issuing
  GET/POST/download and rechecks it immediately after all awaits and
  before displaying a private row or launching the hash-verified PDF.
  An old account's returned bytes can never open a different user's
  document viewer.
- Clears current page/metadata, progress/error/consent on background,
  first inactive mount, account/client or requested source replacement,
  and screen disposal. It performs **no implicit reload or generation**
  on foreground restoration; users must explicitly refresh.
- Rejects forged native document numbers, outside-2^53 source IDs,
  unsupported legal statuses or an unexpected source kind / record ID
  in a filtered listing. Generated documents must match the exact
  originally requested kind, source ID and locale, even when the
  server response is otherwise a valid TEST document.
- Preserves a conservatively **uncertain** result if a generation
  POST was in flight while the view backgrounded. It never silently
  generates a second immutable number, changes source ownership or
  retries a payment. The existing explicitly non-production notice
  remains bilingual.

The new `apps/mobile/test/financial_documents_source_test.dart`
has self-contained Flutter/widget regressions for actual PDF
SHA256 and source status verification, filtered list integrity,
account rotation before a late GET, initial inactive mount, cleared
private documents on background, delayed private PDF revocation,
and an unknown-outcome generation POST that never auto-retries.

These native changes are part of the **same PR #272** and are
not claimed to have passed Android/iOS widget and binary builds
until the exact source workflow completes. The backend's current
account authorization remains the server security boundary;
native source/session checks prevent obsolete private data from
being displayed and are not independent payment or tax authority.


### Immutable source amount precision

`FinancialSources::read`, which builds the actual encrypted
signed-source snapshot before a PDF or CSV is minted, now rejects
non-integral `amount_minor` and source totals beyond the exact
2^53−1 representation bound used by browser/native financial
reporting. PostgreSQL numeric values are validated **before** a
numeric-string-to-PHP-integer cast; this applies across charging
invoice/credit/unpaid credit, subscription invoice/credit, vendor
settlement statement, and consolidated corporate statement sources.
Signed settlement/adjustment lines retain their sign. Line totals
must still exactly match the canonical source parent; underflow,
over-range values and overflowed rollups cannot create immutable
numbered artifacts. The original database is not modified or
silently rounded. The source validator also rejects **PHP floats and booleans**
before `FILTER_VALIDATE_INT`: PHP accepts 100.0 or true as an integer
even though a prior floating-point conversion may already have destroyed
source precision. Database integer values and unrounded decimal strings
remain accepted. Targeted feature regressions explicitly cover both
coercion cases; no existing financial document is regenerated.

New `FinancialDocumentsTest` pure-source bounds regression verifies
zero, exact 2^53−1 positive/negative, first out-of-range value,
fractional strings, exponent strings, negative unsigned amounts and
invalid overlarge numeric strings. Existing genuine immutable
document fixtures must still produce byte-identical original
amounts and pass hash/ledger checks at the candidate source.


### Immediate logout of an already rendered native invoice

A source record that finished loading *before* logout must also
disappear at once. Merely checking `sessionFence()` inside a widget's
next `build()` is insufficient: an external logout may not itself
schedule any repaint, leaving a previously rendered private PDF
control on screen. The shared `EvApi` now emits a non-secret,
synchronous `sessionEvents` generation signal on `forgetSession`
and `close`; `FinancialDocumentsView` listens and immediately
clears its private invoice list, PDF affordance and pending source.
It detaches its observer on API change or disposal. A possible
in-flight generation POST remains marked uncertain rather than
being falsely acknowledged or automatically retried by the new
account. A new Flutter widget regression signs out **after**
the original invoice was already painted, without creating another
HTTP response or synthetic rebuild, and asserts the invoice and
PDF action disappear before returning.

This identical additive `EvApi` event implementation is also
contained in Agent 3's PR #243 for native operations reports.
Agent 5 should reconcile it **once**, not duplicate the interface
while merging the two reviewed branches. All behavior remains
independent of the security role source; persisted account authority
is checked by Laravel on every private operation.


### Already-open private PDF viewer revocation

Clearing the parent invoice page on logout is not sufficient if
the user has already opened a `VendorDocumentPreview` route.
A PDF can remain on the route even when the parent does not
rebuild, preserving its sensitive document content after account
logout. The shared native viewer now supports an **optional**
`revokedSessionEvents: Stream<int>` parameter. On an authenticated
generation event it synchronously removes the PDF/image renderer,
evicts cached image data, displays no source bytes and schedules
navigation back from the private viewer. The listener is detached
on widget update/disposal; other non-account-bound image previews
retain their original API and behavior.

`FinancialDocumentsView` now passes its current `EvApi.sessionEvents`
to the private invoice/credit/settlement PDF route. A new real Flutter
widget regression opens an already-rendered private document route
and confirms `forgetSession` hides/closes the viewer without
backgrounding the application. This is UI confidentiality, not
cryptographic secure erase from all process memory, and is distinct
from Laravel's private-disk, hash and authorization controls.

Agent 5 should preserve the optional viewer revocation parameter
and propagate it to other relevant TEST partner/analytics financial
PDF routes when integrating Agent 3 PRs #243 and #252.


## Exact multi-line document source totals (Agent 3 continuation)

Financial source validation now checks the **aggregate**, not only each
individual amount, when preparing an immutable bilingual consolidated
corporate statement or vendor settlement document. The previous
`array_sum` of individually exact corporate line subtotals, taxes and
totals—or signed vendor settlement debit/credit lines—could produce an
already-rounded PHP float above ±(2^53−1), or silently cast an
unrepresentable total into a fabricated immutable snapshot. The
`FinancialSources::sumExact()` helper validates every signed/unsigned
line and **each running sum** through the existing exact integer
source-money guard. It rejects unsafe source data with 409 **before**
incrementing the document sequence, writing encrypted PDF/CSV or
creating an audited document record. Normal signed vendor refund-debt
lines and corporate invoice totals are unchanged.

Added `FinancialDocumentsTest` regression vectors for PostgreSQL-style
integer strings, mixed signed settlement lines, PHP float/boolean
sources and two individually representable 2^52 totals whose sum is
2^53. The native test document fixture now uses a properly structured
`CB-TDOC-AE-P-2026-000000009` number; a separate negative assertion
continues rejecting the legacy `TEST-PRIVATE-9` token. No legal
invoice approval, live merchant/bank payment, physical charging
or production source acceptance is implied. Exact-current-source
Platform/Finance/Native hosted gates and Agent 5 integration are
required.
