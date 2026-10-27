# Vendor onboarding and inventory API

Status: **Implemented and API-tested development slice**. This is not verified legal KYC, hardware provisioning, or a complete vendor portal. No metadata record can enable physical charging.

## Integration

`routes/vendor_ops.php` contains relative routes and no authentication middleware itself. The main app must register it under an authenticated group:

```php
Route::prefix('/api/vendor-ops')->middleware(['auth', 'throttle:platform-api'])
    ->group(base_path('routes/vendor_ops.php'));
```

The same file can be registered below `/api/v1/vendor-ops` with `auth:sanctum` for native clients. Do not mount it without authentication. `VendorOpsTest` conditionally mounts the web group only when the main app has not yet registered that path.

## Contract

| Method / relative path | Access | Behavior |
|---|---|---|
| GET /companies | Owner / accepted staff / admin | Scoped company list; trade-license number is never serialized |
| POST /companies | Customer/vendor applicant | legal_name, configured country, trade_license_number and future license_expires_on; creates draft |
| GET /companies/{company} | Owner/admin or company.read staff | Metadata and explicit workflow-only / physical-control-disabled flags |
| PATCH /companies/{company} | Owner | Correct pre-submission draft/changes_requested/rejected details only |
| GET/POST /companies/{company}/documents | Owner/admin read; owner upload | Multipart kind=trade_license/identity/agreement + document; private encrypted PDF/JPEG/PNG, maximum 5 MiB |
| GET /companies/{company}/documents/{document} | Owner/admin | Authenticated attachment download; never public storage link |
| POST /companies/{company}/submit | Owner | Requires trade-license evidence and unexpired license; repeat submission is idempotent |
| POST /companies/{company}/review | Admin | Explicit status + reason; audited transition; approval can promote customer applicant owner to vendor |
| GET /companies/{company}/reviews | Owner/admin | Append-only application review history |
| GET/POST /companies/{company}/staff | Owner | View invitations/members; invite registered account with explicit permission list; non-enumerating response |
| GET /staff-invitations | Recipient | Own pending invitations |
| POST /staff-invitations/{membership}/accept | Recipient | Explicit consent; revoked memberships cannot self-reactivate |
| DELETE /companies/{company}/staff/{membership} | Owner | Revoke access; reinvitation requires new recipient acceptance |
| GET/POST /companies/{company}/sites | Scoped inventory reader/writer | Private site hierarchy; optional legacy station link must belong to company owner and match country |
| GET/POST /companies/{company}/sites/{site}/chargers | Scoped inventory reader/writer | Tenant-bound charger metadata, asset reference unique per company |
| GET/POST /companies/{company}/chargers/{charger}/connectors | Scoped inventory reader/writer | Connector number unique per charger, compatible types and bounded power |
| PATCH /companies/{company}/chargers/{charger}/connectors/{connector}/maintenance | Scoped inventory writer | Audited block/reason; unblocking requires approved, unexpired company |

Permissions are `company.read`, `inventory.read`, and `inventory.write`. Owners/admins bypass staff scope checks. Staff cannot invite peers, alter permissions, inspect KYC evidence or self-elevate. Inventory write rechecks company status and staff membership inside a transaction. SQLite tests do not establish PostgreSQL concurrency acceptance.

Review transitions: draft → submitted (owner); submitted → approved/rejected/changes_requested (admin); rejected/changes_requested → submitted (owner); approved → suspended (admin); suspended → approved (admin). Approval is an internal workflow decision, not government/legal certification or hardware safety approval. Inventory creation requires approved status and a currently valid license. Suspension does not disable existing legacy station APIs automatically; their integration remains required.

## Security and truth labels

Trade-license numbers use Laravel encrypted casts; evidence bytes are encrypted with Laravel Crypt and stored on the private local disk under generated filenames. The application key must be securely retained alongside encrypted backups; changing it makes documents unreadable. Responses hide storage paths. MIME/size checks and attachment-only downloads reduce exposure, but antivirus scanning, production object-store encryption/access policies, retention and regulatory privacy review remain release gates. Production PHP/proxy upload limits must support the documented 5 MiB application limit.

Charger sources are discovery, simulator, direct_unverified and partner_unverified. Integration status starts unverified. Availability remains unknown and remote_control_enabled stays false. User-supplied fields claiming availability, hardware verification or control authorization are ignored. This hierarchy does not directly share CitrineOS tables or bypass its provisioning and authenticated command/event adapters.

Existing legacy station reservation/simulation APIs remain unchanged. Site metadata can link to an owner-controlled legacy station, but connectors are not yet the reservation inventory and maintenance blocks do not yet govern those legacy bookings. Complete shared web/native onboarding, inventory UX, global multi-role personas, public normalized catalog synchronization, connector-level bookings, actual OCPP provisioning/health and document-delivery/storage operations remain unfinished.

Staff permission is independent of the User.role field. A customer-role user who accepts inventory staff access can use these scoped APIs; the current role-only vendor mobile sign-in must be extended to support that persona before claiming staff mobile completion.

## Evidence

`./scripts/php artisan test --filter=VendorOpsTest --compact` passes eight meaningful tests covering applicant/admin transitions, evidence encryption and private downloads, tenant separation, staff consent/scope/revocation/reinvitation, hierarchy integrity, truthful capability labels, expired/suspended company gates, protected edits and invalid files/permissions. Exact latest assertion count is in the test output; full platform/real KYC/hardware acceptance is not claimed.

## Connected web workflow and renewal

The shared authenticated web workspace now covers the mounted company/evidence/review/staff/site/charger/connector/maintenance APIs, with pagination, EN/AR, actual permission hints and customer-role staff personas. Private evidence fetches validate binary MIME/size, trigger attachment downloads and revoke temporary blob URLs; no public storage URL or plaintext license number is persisted in the browser. Upload responses are consumed before replacing forms. All mutations retain server CSRF and authorization checks.

GET company/list now includes capabilities (owner/reviewer, company/inventory read/write, private evidence, inventory_operational). These are UI hints; the server still rechecks active membership and company validity for operations. Pending staff invitations expose only company ID/name/country needed for consent, never license/evidence fields.

Admin changes_requested is allowed from approved or suspended to support audited renewal; owner edits/resubmission and admin reapproval remain required. License operational validity is evaluated at the configured country calendar end of day, not server midnight. Current code/tests supersede the earlier API-only and renewal-incomplete checkpoint. Legal KYC, AV/storage/retention review, actual provisioning and connector reservation linkage remain open.

## Catalog linking and charging policy

The site form offers scoped paginated/searched simulator station choices without translating user names. Selecting a station copies its country/timezone/coordinates; an empty selection preserves an independent inventory site. Linking immediately gates new bookings/starts until one matching simulator connector is configured. Company approval, current local-date license, maintenance/type/power and binding protocol/connector-1 are enforced. Multiple targets are conservatively blocked until source mappings exist. Stop/finalization are never disabled by company maintenance. Legacy unlinked inventory and full native company workflows remain separate continuation work.

## Native inventory maintenance

`apps/mobile/lib/vendor_inventory.dart` consumes company/site/charger/connector pages and existing PATCH maintenance. Owners and consented customer-role staff retain the same backend scopes; derived read/write/operational hints do not replace current server authorization. Native does not provision equipment or infer live availability. Read-only traversal, confirmation/reason, uncertain-write refresh, expiry/revocation/disposal and Arabic phone layout are covered by unit/widget tests and a separate actual-Laravel client acceptance test. Native full KYC/company/staff and inventory creation are still separate unfinished workflows.

## Canonical topology continuation

See [CANONICAL_TOPOLOGY.md](CANONICAL_TOPOLOGY.md) for new scoped EVSE/mapping APIs, connector choices, immutable source/booking snapshots, parallel capacity and protocol proof. Legacy contracts/history remain; direct-managed sources are simulation-tested only. Current owner handoff is MASTER_AGENT2_CONTINUATION.md, which supersedes historical account restrictions.

## Secure charger admission and no-source case operations

See [CHARGER_ADMISSION.md](CHARGER_ADMISSION.md) for new scoped APIs, owner/Admin web and vendor Flutter controls, TLS/mTLS simulation evidence, credential/version/replay safeguards, source inspection and remaining production boundaries. Financial truth remains Agent1-owned.
