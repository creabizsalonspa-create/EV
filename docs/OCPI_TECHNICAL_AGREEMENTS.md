# OCPI technical-agreement onboarding

Agent 2 continuation of original requirement **6.4.8**, based on `f4f22e80`. This is nonfinancial bilateral technical trust; commercial prices, invoices, payouts and accounting remain with the canonical commercial workstream. Agent 5 alone integrates verified changes.

## Workflow and trust boundary

An active Admin proposes an immutable, encrypted version against a securely configured peer. The server derives the counterparty/local country, party, opposite CPO/eMSP roles, approved origin hash, registration direction and inbound-module configuration. Clients cannot supply an endpoint, token, alias or arbitrary authority. Drafts include bilingual technical terms, the external evidence-review reference and document SHA-256, data-module scope and UTC effective/expiry times. The document hash records a human-reviewed external evidence reference; it is **not** automatic verification of a signature, legal approval or a live partner agreement.

Provider/head locks serialize monotonically versioned drafts and decisions. Exact request keys recover lost acknowledgement without duplicating versions/events; mismatched intent, stale revisions, changed peer/owner and already decided proposals fail closed. Approval or revocation withdraws existing provider authorization, negotiated endpoints and credential grants. Approval **never activates a partner**: separate existing Admin authorization and credentials enrollment remain necessary. Rejected amendments retain earlier historical evidence and do not silently replace an effective approved version. Version and decision rows have database UPDATE/DELETE protection in SQLite/PostgreSQL.

Production execution always requires an effective approved agreement, even if connection metadata is labelled sandbox. Local/testing sources can explicitly opt into the same policy with `technical_agreement_required=true`; existing legacy sandbox protocol fixtures retain their original boundaries. Scope enforcement covers explicit activation, outbound partner reads/credential transport, inbound protocol authentication/record modules and SQL-paginated public discovery/favorite source hydration. Expired, changed or held-owner sources disappear from publication without a worker, while historical source/financial records remain retained. New production CPO/eMSP command rights are not invented.

The Admin interface is attached to existing roaming provider operations. It provides private history, bilingual proposal/review/revocation, scope/date/evidence inspection, explicit exact-action retry for ambiguous acknowledgements and parent-provider refresh after authority withdrawal. Its lifecycle clears private DOM/drafts on background/removal; late requests and saved callbacks use current actor/generation guards. Text is literal, with no external document/provider fetch. Existing browser CSRF/authentication/Admin MFA gates apply; locked writes recheck current Admin role/password/epoch and current MFA enrollment/session age when MFA is required.

## Endpoints

Web `/api/ocpi/providers/{provider}/technical-agreements`, native-prefixed API `/api/v1/ocpi/providers/{provider}/technical-agreements`: GET private paginated history and POST a proposed version. POST `{agreement}/decision` records `approved`, `rejected` or `revoked`. Admin bearer access remains rejected by existing policy; no customer/vendor contract-write interface is added. Responses are private/no-store and explicitly say no external request, provider activation, command/billing authority or live acceptance.

## Current verification checkpoint (in progress)

- All current OCPI/partner/unified-search regression tests: **203 passed / 3,088 assertions** before the added dedicated MFA test.
- Focused agreement policy/API tests: **9 passed / 97 assertions**, including exact-key recovery, permissions, source publication/saved-reference expiry, configuration changes, immutability and locked MFA enrollment/age rechecks.
- Controlled Chromium module: **10 checks passed**, including ambiguous retry, explicit evidence approval, capability rejection, private lifecycle, identity changes and 390px Arabic.
- Existing production tracing tests now create the real policy precondition before testing their independent mandatory trace echoes. Neither guard is mocked, disabled or removed.
- Actual isolated Laravel/Chromium English flow completed; Arabic initial-open is under investigation and **not credited**. PostgreSQL process races, full Laravel and resulting hosted exact-source CI remain pending. No full 6.4.8 coded/tested/merged credit is asserted at this checkpoint.

The new browser fixture creates only its own generated SQLite database, key and synthetic identities. It never resets an existing database or original charging/finance journal. All test partner origins/credentials/evidence are synthetic and no live provider, hardware, money movement or production activation is authorized or attempted. Real agreements and partner interoperation remain external acceptance requirements, separate from missing software verification.
