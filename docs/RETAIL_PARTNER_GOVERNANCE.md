# Retail partner administration

Original requirement target: **4.4.6**, Super Admin retail partner administration. This is an internal governed registry, with an actual dedicated Admin web workflow at `/admin/retail-partners` linked from the existing Admin workspace. It is not customer reward delivery, a PSP, a wallet, a roaming-provider registry, legal approval, or live partner activation. Those boundaries remain separate and uncredited.

## Implemented workflow

An active current administrator registers an immutable reference and bilingual legal/display terms, country, category, optional HTTPS website and explicit UTC effective/expiry timestamps. The initial state is draft. A different administrator must review and approve the exact version. The creator cannot approve or reinstate their own version. Approval never claims that future or expired terms are currently effective.

An independent reviewer can reject a draft with an audit reason. Rejected terms cannot be approved directly. Administrators can revise rejected, suspended or draft terms into a new immutable draft that requires independent review. Approved records can be suspended or permanently terminated. Active terms must first be suspended; terminated records cannot be revived. Every mutation requires a five-character reason, explicit expected revision (except creation), and a UUID request identity. Changed or stale requests conflict. Exact creation/mutation retries return the existing record without appending duplicate events or audit rows. The browser keeps a creation identity across a lost response and retries only after an explicit user action.

Partner identity, every version and all decision events are protected against UPDATE/DELETE by SQLite/PostgreSQL triggers. Every decision appends the canonical audit trail. A populated non-testing downgrade refuses to discard history. PostgreSQL creation serializes reference allocation on a persisted shared configuration row, rather than assuming an absent reference can be locked. Per-partner row locks and unique revisions prevent simultaneous reviewers from consuming the same revision.

The screen supports reference search, bounded pagination, historical terms and reasons, bilingual creative as literal text, EN/AR RTL, persistent existing theme/language preferences, loading/error/conflict states, mobile-width layout, and private-data clearing on lifecycle/session loss. No external website is fetched or embedded. No browser-provided markup becomes HTML. A source response arriving after a lifecycle generation change cannot restore the private workspace.

## API

Normal web session/CSRF routes use `/api`; native-compatible API routes use `/api/v1`. Existing authentication, production rate limits, account-status gate and Admin MFA remain in force. Admin personal access tokens cannot acquire registry authority. All reads/mutations recheck current active administrator credentials under a transaction row lock. Responses are `private, no-store`; decision request hashes and request keys are not exposed by read APIs.

| Method | Relative route | Result |
| --- | --- | --- |
| GET | `admin/retail-partners?page=1&search=REFERENCE` | Standard 25-item paginator. Search is optional/nullable and reference-scoped. |
| POST | `admin/retail-partners` | Register `{reference,legal_name,display_name,display_name_ar,country,category,website:null|string,terms,effective_from,expires_at,reason,idempotency_key}`. Returns201 `{partner}`. |
| GET | `admin/retail-partners/{id}?page=1` | `{partner,events}` with bounded paginated immutable decisions and their exact historical version snapshots. |
| POST | `admin/retail-partners/{id}/{approve,reject,suspend,reinstate,terminate}` | `{expected_revision,reason,idempotency_key}`, returns `{partner}`. |
| POST | `admin/retail-partners/{id}/revise` | New immutable terms plus `{expected_revision,reason,idempotency_key}`; reference remains fixed. |

`category` is `retail`, `hospitality`, `food`, `automotive` or `services`. Country must be configured. Website must be HTTPS. Dates include an explicit UTC offset (`Y-m-dTH:i:sP`). The projection exposes state, latest version, revision and computed `currently_effective`, with `external_fulfillment_verified=false`, `rewards_enabled=false`, `money_movement_enabled=false` always. No country-wide, partner-credit or payment authority is inferred.

Errors preserve normal401/403 authentication/authority,404 unknown resource,409 stale revision/changed identity/state/independent-review failures,422 invalid fields,419 CSRF and429 production rate limits. Mutations are never automatically retried. After a failed decision the UI removes stale controls and requires a fresh record before another decision.

## Verification

- `RetailPartnerTest`: lifecycle, independent approval, expiry/future terms, immutable versions, exact retries, duplicate references, stale credentials, role/MFA/token denial, validation, bounded complete pages and version history.
- `RetailPartnerConcurrencyTest`: actual independent PostgreSQL processes race the same creation identity, different administrators competing for one reference, and competing approval/termination at the same revision.
- `tests/retail-partners-e2e.cjs`: actual Laravel/browser registration with a committed-but-lost response followed by a same-key explicit retry; independent review; suspension/revision; bilingual terms, literal malicious display text, RTL/themes, mobile width and customer denial.
- `tests/operational-connected.cjs` runs the real browser suite in its own unchanged authentication window. The existing finance PostgreSQL matrix includes the feature test and all process tests; no prior filter/suite was removed.

A registry entry is not evidence that an external merchant signed a contract or fulfilled a reward. Operational onboarding, approved legal terms, real retail fulfillment, production activation and customer partner-reward integration remain separate. No live transactions or activation were performed.

## Partner reward reviews

The internal review workflow for requirement 2.9.7 associates a canonical noncash reward with the exact effective retail partner version and revision. The reward must use the configured currency for the partner country and fit entirely within the contractual window. Another administrator must approve or reject it. An administrator may revoke approval. Suspension, reinstatement, or any subsequent partner revision invalidates the prior terms match and requires a fresh review.

At `/api/admin/retail-partners/{partner}/reward-reviews`, GET lists 25 reviews per page, and POST submits `{reward_id,expected_partner_revision,reason,idempotency_key}`. GET `/{review}` retrieves paginated immutable decision evidence. POST `/{review}/{approve,reject,revoke}` requires `{expected_revision,reason,idempotency_key}`. Each request uses the registry's current Admin authority. Exact retries keep one event, and changed content or revisions conflict. Review records, including reasons, are available only to administrators.

A review records internal approval of the association. It does not change the reward catalog, enable or execute fulfillment by the partner, or create a financial or loyalty entry. The API reports `fulfillment_enabled=false`. Administrators can propose an existing reward, open its paginated decision history, approve or reject a proposal, and revoke approval from the bilingual retail partner workspace.

## Synthetic customer claims

With sandbox loyalty enabled, `GET commercial/partner-rewards/offers` lists current approved offers, `POST commercial/partner-rewards/offers/{review}/claim` accepts an `idempotency_key` from a verified customer, and `GET commercial/partner-rewards/claims` returns the customer's private paginated receipts. The routes work under the existing web and native API prefixes.

A claim retains the exact approval event and reserves the canonical reward inventory at zero points. The existing Admin redemption workflow handles manual decisions. A revoked review, expired terms, or changed partner revision prevents fulfillment and new claims. In sandbox, Admin rejection can release a pending reservation, and receipt history preserves its original source. Claims and fulfillment are sandbox-only even under the reviewed manual production setting. Production partner activation, external delivery evidence and customer app screens require separate work.
