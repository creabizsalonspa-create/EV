# Internal QA — universal integration checkpoint

## Admin OCPI connection association and native expiry-navigation checkpoint

Admin can register providers against existing approved server connection aliases using a validated connection selector and paginated/searchable vendor names/IDs. Credential values, token variable names, endpoint origins and raw connection metadata never enter this API/UI. Only structurally valid, unassigned connections are selectable. Registration starts unauthorized/revoked and makes no partner request; existing explicit authorization/negotiation/sync remain separate operations. Newest-first provider paging exposes newly registered records. This does not implement credentials provisioning/rotation or full bilateral OCPI roles.

Private generations and abort signals cover all new options and forms. Registration requires confirmation; a lost acknowledgement removes resubmission controls and requires authoritative refresh. A real-backend fixture commits creation then drops its response, proving one retained registration without replay. Selector failure leaves existing provider operations usable. Backend guards retain admin-only selectors/creation, vendor-role owners, server-configured aliases and unique connections. Owner choices return only ID/name, never email or private account fields.

Native inventory now also avoids reloading parent scopes when nested authentication expiry pops all routes to the root. A dedicated regression proves one expiry callback, no additional removed-scope requests and no remaining private rows.

Verified locally: **121 Laravel tests / 5,898 assertions**, Pint **132 files**; OCPI suite **17 / 86**. Full native analysis clean and **46 unit/widget passes / 2 explicit default live-only skips**, with **9 inventory unit/widget cases**; separate actual-Laravel inventory test remains **1 pass**. Existing real roaming, frontend-contract and localization browser suites pass, as does the new isolated actual-Laravel registration/lost-ack/selector-failure/RTL test. Its server uses synthetic injected connection metadata and no partner requests; `--no-reload` is required so Laravel's serve command retains those injected test settings. No production environment file is changed.

Hosted **cabac72** native run **40506100423 is green**: Android debug, unsigned iOS simulator artifacts, Linux actual-Laravel inventory test and **1 iPhone TLS/Keychain/partner/invoice/Arabic-pricing/RTL scenario**. Fresh run-owned simulator creation/boot/cleanup passed after prior pre-Dart failures on reused devices; this single pass is evidence at that revision, not a blanket guarantee against future runner failures. Current source adds only the independently tested nested-expiry regression and OCPI work; its own CI must be assessed separately. Evidence: ocpi-provisioning-* and native-inventory-hosted-*.

Financial PR #4 at **60a5dd0** has both platform and PostgreSQL checks green, but shared continuation documentation conflicts with the newer EV branch. Preserve both accounts' commits and records through a separate integration PR; do not edit or recreate financial business logic. All standing ownership boundaries in NON_FINANCE_CONTINUATION remain exclusive while both agents are active.

Next EV scope remains explicit connector/source/EVSE mapping and parallel reservations, operational reconciliation and actual authorization lifecycle, complete OCPI interoperability, maps, full native company/KYC/staff/creation and non-financial Admin/security workflows. Real partner/hardware/signing/legal acceptance is still unverified; this is not a finished platform.


## Native company inventory and maintenance checkpoint — verified locally

Customer-role staff and vendor owners can open Company inventory from the shared native Account menu. Paginated company → site → charger → connector routes use existing tenant-scoped APIs and derived permission hints. User names/addresses/reasons stay literal; source/status labels and all controls are EN/AR with inherited RTL/themes. Connector availability remains explicitly unknown and the interface never provisions hardware or sends a charger command.

Authorized writers can block new starts or remove maintenance with a required literal reason and explicit confirmation. Unapproved/expired companies cannot use the hinted release action; the server independently checks current scope and approval, including revocation. Responses are refreshed authoritatively, not applied optimistically. Lost acknowledgement clears private rows, warns that the mutation may have committed and requires a GET refresh before another explicit action; no PATCH is automatically replayed. Scope failures clear records, authentication expiry calls the shared handler and returns to the root, and disposed routes ignore late responses.

Verified: Flutter analysis clean, **45 unit/widget passes / 2 explicit default live-only skips**, including **8 new inventory unit/widget cases**; separate actual-Laravel native inventory **1 passed**, proving owner maintenance, customer-role read-only staff, unauthorized release rejection and authoritative recovery. The prior expiry checkpoint remains **118 Laravel / 5,862 assertions**, PostgreSQL **55 / 5,400** plus **4 concurrency / 54**; both protocol source-backed integration flows passed. Pint now covers **129 files** including the new isolated fixture. No financial logic/API/client file was changed.

CI now runs the actual native inventory client against local Laravel on Linux and builds Android/iOS. The pre-Dart macOS log-reader/VM-service failure recurred on `940ccbf`; fresh per-run iPhone18.5 simulator creation/boot/cleanup now replaces preinstalled device reuse as a diagnostic correction. Hosted execution of this revision is pending; do not call the launch issue resolved or latest builds verified until those runs pass. No physical device/signing/store acceptance is implied. Evidence: native-inventory-*.

Still unfinished: native company create/edit/KYC/evidence/staff invitation workflows, inventory creation/linking, actual provisioning/live health, full connector/source mapping and parallel reservations, complete OCPI roles and maps. Agent 1 exclusively owns financial/commercial logic and backend/web/admin finance; Agent 2 owns EV/non-financial software and may consume completed finance APIs in Flutter. Finance `d1f843d` is not integrated without a completed verified PR.


## Known-unsent charging expiry checkpoint — verified locally

Added a bounded simulator-only expiry service, `csms:expire-unsent` command and minute scheduler registration. At the exact 30-minute window end, only a locked pending start with no admitted dispatch, source transaction/inbox/meter/energy/end or calculated total can close. Reservation/session become `expired_unstarted`; the command becomes cancelled with `expired_before_dispatch`; source `ended_at` and transaction remain null. A separate uniquely keyed system-expiry record preserves operational evidence without attributing customer cancellation. Existing billing/ledger records are untouched. Binding reuse and observer exclusion follow the existing closure contract.

Scheduled batches use a cached ID cursor, revisit after the end and survive cache loss safely, so unresolved records cannot starve later eligible requests. Cache is an optimization; reservation/command/link/session locks and unique evidence remain authoritative. A supervised Laravel scheduler must actually run; registration alone is not a deployed worker. Simulator bridge feature gates and production denial remain enforced. No hardware command, authorization-token revocation or financial posting is performed. Uncertain/admitted/source-active starts require reconciliation; no-session no-show/check-in and complete production lifecycle remain unfinished.

Verified: **118 Laravel tests / 5,862 assertions**; PostgreSQL finance/managed/vendor **55 tests / 5,400 assertions**, plus **4 process-concurrency tests / 54 assertions** including two simultaneous expiry workers closing once. Native analysis clean and **37 unit/widget passes / 1 default live-only skip** at the expiry source. Real browser linked-company/maintenance/cancel/CLI-expiry/replay/shared EN/AR state and localization suites pass. Both OCPP protocols still produce the same single simulated source-backed invoice/payment/refund reconciliation. No finance implementation file changed. Evidence: `unsent-expiry-*`.

The later native inventory work is a separate checkpoint. Source `940ccbf` has both platform CI runs green; native PR execution still has a pre-Dart simulator log-reader/VM-service launch timeout at the pinned baseline, distinct from the earlier successful device run. Do not report all hosted native runs green.


## Hosted CI selector follow-up

At `62b4d51`, push platform validation passed (40502188767), while PR validation (40502194030) exposed a timing-sensitive strict locator: newly created station headings legitimately exist in both catalog and tariff workspaces. The browser acceptance assertion now scopes to `#stations`, preserving its exact-name assertion and production behavior. No throttles or application permissions were relaxed. The full real-Laravel browser suite passed locally with the scoped assertion; evidence: `docs/evidence/catalog-selector-browser.txt`.

Native baseline push `890b081` passed Android/iOS builds and the complete iPhone integration scenario (40499612397). Its separate PR native run (40499622088) timed out before any Dart device phase, with a simulator log-reader/debug-connection failure, despite the same pinned Xcode16.4/iOS18.5 baseline. This intermittent hosted launch issue remains unresolved; the successful run must not be described as proof that every PR/device run passes. The newer `62b4d51` native run is tracked separately.


## Web account-context response isolation checkpoint

Added protected-response generations before headers/status handling and after JSON/body completion. Logout, login intent, expiry and verified principal/role changes invalidate older private work; stale 401/204/error responses cannot affect a newer account, and public catalog reads can finish independently. Unknown identity payloads fail closed. Raw private-file expiry handlers also respect their workspace generation. No request is automatically replayed and production CSRF/cookies/rate limits are unchanged.

Logout immediately clears private views, file URLs and reservation actions. Network failure is explicitly unconfirmed and provides a localized retry. A non-secret per-tab pending-logout flag retries the original sign-out intent after reload instead of restoring private identity; successful deliberate sign-in clears it. Authentication fetches fresh CSRF tokens and serializes auth intents while allowing sign-out after identity verification, without waiting for optional workspace data. This is per-document response isolation, not a claim of complete cross-tab/global session revocation or full security acceptance.

Verified client ordering tests cover stale protected headers, streamed JSON, 401/204, malformed/failed responses, stale CSRF, current identity/public reads and no replay. Controlled browser fixtures prove old private vehicles/401 cannot overwrite a new account; role downgrade blocks late admin data; unreadable identity clears private views; failed logout/reload behavior and Arabic errors work. Separately, all existing real-Laravel e2e/vendor/roaming/invoice/tariff/managed-gate browser suites plus frontend contracts/localization pass with the new auth flow. Fixture tests are not substituted for backend authentication acceptance. Evidence: web-session-* files.

Hosted **890b081** is green for platform and native workflows. macOS15/Xcode16.4/iOS18.5 successfully built the app and ran **1 iPhone TLS/Keychain/partner/invoice/Arabic-pricing/RTL test**; Android debug and unsigned iOS simulator artifacts are retained. This resolves the previous localized back-helper failure and rolling iOS26.5 debug-launch timeout at the tested baseline. Current iOS26/store-SDK compatibility, signing and physical release acceptance remain separate unfinished gates. Backend/native source is unchanged by this web-only privacy slice: **106 Laravel / 5,754 assertions**, PostgreSQL managed/vendor/finance **43 / 5,292** plus concurrency **3 / 39**, native **36 unit/widget / 1 default live skip**, separate actual-Laravel native **1 pass**.

Next full-scope work remains explicit connector/source identity and parallel reservations, automatic known-unsent expiry, production CSMS authorization/operations, PSP/webhook software, subscriptions/settlements/loyalty/maps and remaining native company/operator/admin workflows. Follow MASTER_CONTINUATION and REQUIREMENT_STATUS; no complete-platform or production-release claim is made.


## Managed simulator admission and undispatched cancellation checkpoint

Company writers can now choose/search/page owned unlinked simulator catalog stations when creating a site; raw station names are preserved across language changes and selected country/timezone/coordinates are copied explicitly. Linked catalog stations enforce approved/current-license company state, one unambiguous matching simulator connector, maintenance, power/type and existing CSMS protocol/connector-1 targeting. Public web/native shows a generic managed-unavailable state and hides reservation actions without leaking KYC or maintenance reasons. Catalog policy loading batches metadata and materializes only unique connector candidates. Unlinked legacy simulators remain compatible; this is not complete multi-connector or mandatory production company ownership.

New reservations and starts check policy under transaction locks. CSMS dispatch claims recheck policy and reservation window before any HTTP request; blocked work stays provably unsent/pending with a typed reason. Stop, meter reconciliation and finalization remain available after maintenance/suspension. Customers can cancel only a pending start proven never admitted to dispatch. Cancellation atomically marks command/session/reservation cancelled, records a separate closed_without_dispatch_at (never a fake source end/transaction), releases the binding, skips observation and rejects unexpected later source activity. Admitted/submitted/rejected/uncertain commands cannot use this path. Existing web/native controls expose the safe cancellation with confirmation and refreshed state.

Verification: Laravel **106 tests / 5,754 assertions**, Pint **121 files**, Composer strict valid; isolated PostgreSQL finance + managed/vendor workflows **43 tests / 5,292 assertions**, plus **3 concurrency tests / 39 assertions**. Flutter analysis clean, **36 unit/widget passes / 1 explicit default live skip**, actual-Laravel native integration **1 passed**. Real browser company linking → empty-site block → matching connector recovery → maintenance block → pending-CSMS cancellation passes; existing e2e/vendor/contracts/localization pass. Both OCPP versions pass signed source → one simulated invoice/payment/refund with manual and actual restarted continuous consumers after the new closure migration. Evidence: managed-gate-* files.

Hosted platform checks passed **75451a9** and both Android/iOS builds succeeded, but iPhone pricing integration failed at the RTL back-navigation test helper after the pricing assertions. The helper used English-tooltip/Cupertino fallback against a localized Material route. It now targets BackButton directly and has an Arabic widget regression; updated hosted device execution is pending until the new source run passes. Failed evidence is preserved rather than called successful. Public roaming now loads independently so it does not block account restoration; browser QA waits for asynchronous identity readiness after reload.

Remaining software: explicit multi-connector source/EVSE mapping and parallel reservations, mandatory canonical company ownership, automatic known-unsent expiry closure and operational reconciliation, production CSMS auth/dispatch/token lifecycle, full PSP/subscriptions/settlements/loyalty/maps and remaining native company/operator/admin parity. Cancellation does not revoke a real roaming/charger token; real authorization lifecycle and hardware acceptance are separate unfinished work. No physical command/payment/partner or final release is claimed.


## Versioned pricing, commission policy and retry safety checkpoint

Added scoped paginated pricing-station selectors and web vendor/admin + native vendor tariff interfaces. Prices accept exact English/Arabic/Persian decimal input using integer arithmetic for 0–3 currency digits; ambiguous separators, exponent notation, overflow and silent rounding are rejected. Admin web can set commission; vendor publication retains the latest administrator commission policy instead of resetting it to zero. Existing quotes/invoices retain their original snapshots. These interfaces intentionally operate on the existing simulator-station contracts; commercial company-inventory linking and real billing remain unfinished.

Publishing now accepts an optional actor-scoped idempotency identity and payload fingerprint, stored in additive nullable immutable-tariff columns with a unique key. Station/region transaction locks serialize publication; matching retries return the original version, conflicting payloads fail 409 and audit events are not duplicated. New clients send expected currency/precision and refresh authoritative pricing context, preventing a stale currency precision from silently changing the price. Lost transport acknowledgement blocks further UI publication until history refresh; no automatic POST replay is introduced. A downgraded vendor cannot read private tariff history.

Verified locally: Laravel **91 tests / 5,623 assertions**, Pint **117 files**; PostgreSQL finance **17 tests / 5,051 assertions**, plus **3 concurrent-process tests / 39 assertions**, including two simultaneous tariff retries resolving to one version/one audit with retained commission. Native analyzer clean, **33 unit/widget passes / 1 explicit default live skip**, separate real-Laravel native test **1 passed**, now covering scoped pricing selection, idempotent publication and unchanged earlier invoice. Browser acceptance verifies admin/vendor publishing, Arabic inputs, 26-station pagination, a real committed/lost acknowledgement with identical-key replay and exactly three immutable versions, tenant/role privacy, RTL phone layout and logout. Existing e2e/vendor/invoice/contracts/localization pass. Logs: evidence/pricing-*; retained synthetic financial fixtures are never edited/deleted.

Hosted source **a043552** passed both platform runs, Android debug and iOS simulator builds and **1 iPhone TLS/Keychain/partner/invoice-history integration test**. Updated native pricing/device scenario and new migration require their own hosted result after this push. No physical charger, PSP, partner roaming, legal tax or production readiness is implied.

Next unfinished integration: connector-level reservation/availability and company-maintenance gates, then production-safe CSMS dispatch/reconciliation, complete PSP sandbox/webhook software, subscriptions/settlements/loyalty/maps and remaining native company/operator/admin workflows. Continue the full MASTER_CONTINUATION objective; do not recreate the now-implemented company, roaming, invoice-history or pricing interfaces.


## Invoice history and receipt interfaces checkpoint

Implemented tenant-scoped invoice-number prefix/currency/inclusive UTC date filters in the shared cookie/token finance API; strict literal/date validation covers invalid calendars, year zero and the maximum four-digit year. Existing unfiltered pagination is preserved. Added customer/vendor/admin web invoice history, scoped detail, read-only collection/refund receipts, literal refund reasons and vendor/admin simulated allocation summaries. No payment instruments, capture/refund mutation or payout controls are exposed by these screens. Allocations are explicitly before refunds and are not settled/payable balances.

Canonical Flutter now has Account → Invoice history with matching filters/pagination, retained failed-input/retry state, strict date validation, scoped GET detail, read-only receipts and expiry cleanup. Existing session-idempotent invoice issuance remains intact; both detail paths validate simulated evidence. EN/AR/RTL and generic workspace navigation resources are supplied, and localization QA now checks literal workspace UI labels against explicit resources. Native TLS integration includes invoice navigation; its updated device/build execution is pending for this checkpoint.

Verified locally: Laravel **89 tests / 5,591 assertions**, Pint **115 files**; PostgreSQL finance **15 tests / 5,019 assertions** plus **2 concurrency tests / 26 assertions**; native analysis clean, **30 unit/widget passes / 1 explicit default live skip**, separate actual-Laravel native integration **1 passed**. Invoice browser acceptance verifies 26-record pagination/filtering, customer/vendor allocations, cross-tenant 404, read-only receipts, safe user text, Arabic phone layout and logout; existing e2e/vendor/contracts/localization pass. Evidence: invoice-history-* files. Synthetic immutable finance fixtures are intentionally retained, never deleted or edited.

Roaming source **c4f8243** passed hosted Android debug/iOS simulator builds and **1 iPhone TLS/Keychain/partner-pagination test**; artifacts/phase evidence is retained. Both hosted platform runs at **d5ed944** pass the explicit real-429 authentication regression. These results do not prove the newer invoice device scenario until its own CI run passes. The owner’s standing full-scope prompt is retained in MASTER_CONTINUATION.md and linked from AGENTS.md.

Next active implementation: vendor/admin station tariff management with native vendor pricing and preservation of administrator commission policy. Remaining PSP/webhooks, subscriptions, settlements, loyalty/maps, connector-level booking/maintenance and complete operator/company mobile workflows remain unfinished; no production or full-platform completion is claimed.


## Roaming browser authentication budget follow-up

The push platform run at c4f8243 passed, but the parallel PR run 40483716348 failed waiting for the provider workspace after a throttled login. The roaming browser test now checks login responses and waits a bounded numeric Retry-After once only for explicit HTTP 429. It never retries a transport failure/503 and does not change production limits. CI deliberately consumes the local synthetic invalid-login budget and asserts that the real limiter rejects excess requests before a successful login. This makes authentication security part of the browser acceptance test rather than relying on suite timing. Local real-throttle proof is recorded separately; subsequent hosted status must be read from revision-specific evidence.


## OCPI web and Flutter workflow checkpoint

Added a separate paginated partner-location catalog on web and canonical Flutter, with coordinates, source/receipt timestamps, reported EVSE statuses and connector types. No reported status grants availability, reservations, charging or payment authority. Both clients fail closed on unexpected public capability flags. Native route supports retry, loading/empty states, English/Arabic and RTL without replacing the existing catalog or navigation.

Vendor/admin web exposes scoped paginated providers and normalized locations/tariffs/sessions/CDRs, manual confirmed negotiation/data pulls, and admin authorization/revocation. Connections still require secure server provisioning; browser never receives connection keys, endpoints, raw payloads or operator tokens. Private views clear/invalidate pending results on logout. No external partner is configured or contacted by acceptance tests.

Verification: Laravel **88 passed / 5,571 assertions**, Pint **114 files**; Flutter analyzer clean, **27 unit/widget passed / 1 explicit default live skip**, including three new capability/pagination/retry/RTL/disposal tests. Real-Laravel roaming browser passed pagination, 503 recovery, owner isolation, admin revocation/public removal, literal user text, 390px Arabic layout and logout. Existing frontend contract/localization suites pass. Native on-device TLS fixture now also tests partner catalog navigation/pagination; updated Android/iOS hosted builds and device execution remain pending for this source until their CI run completes. Logs: evidence/roaming-*.

Remaining: full OCPI credentials handshake, push/tokens/commands, reconciliation/conformance and authorized partner acceptance; secure admin connection onboarding and broader native operator/company workflows. Complete connector-level reservations/company maintenance, production CSMS workers, actual PSP sandbox/webhooks, subscriptions, commissions/settlements, loyalty/maps, remaining customer/vendor/admin workflows and release/security/device gates are unfinished. These are software continuation tasks, not all external blockers. No final-release claim is made.



## Company, staff and inventory workspace checkpoint

Implemented EN/AR web company applications/edits, private evidence upload/authenticated download, required-evidence submission, internal admin review/history, granular staff permissions/recipient consent/revocation, and scoped sites/chargers/connectors/maintenance. Company capabilities are server-derived hints and never replace transaction-time authorization. Customer-role staff now have a usable company workspace without global vendor promotion. Pending invitations include only recipient-authorized company identity. Private data and blob URLs clear on logout; user-entered names/review reasons are never translated as UI resources.

Added audited renewal: admin can request changes from approved/suspended state, owners correct evidence/expiry and resubmit, and inventory remains blocked pending approval. License validity uses the configured country timezone's end of day; API tests cover the Dubai/UTC boundary and changed configuration. This is an internal workflow, not legal KYC or physical control certification. Legacy station/booking integration with connector maintenance and native company screens remain unfinished.

Verification: Laravel **88 tests / 5,571 assertions**; company-focused **11 tests / 110 assertions**. Real-Laravel browser workflow passed application/private-file privacy/review/customer-role staff hierarchy and revocation, nested-phone layouts, RTL/input preservation and logout; existing browser/contracts/localization passed. CI executes the new vendor browser suite. Reports: evidence/vendor-workspace-*.

Hosted platform and native workflows both passed at **36828ee**, including PostgreSQL finance/races, both continuous OCPP flows, Android debug, unsigned iOS simulator and **1 passed iPhone TLS/Keychain/RTL integration**. That native tree is unchanged by this web/backend vendor slice. Exact source/build/retention metadata: evidence/fullstack-*-ci.json, fullstack-native-artifacts.json and fullstack-device.txt. Production rate limits remain unchanged; bounded 429 and reconnect test fixes are verified. No complete-platform/release claim is made.


## Continuous source and connected clients checkpoint

Implemented a restartable authenticated local CSMS observer with durable source cursors, exact Wh/kWh conversion, station/authorization/remote-start correlation and HMAC inbox delivery. It never dispatches commands. Both OCPP versions passed with an actual continuous process restarted during charging, one immutable simulated invoice, simulated payment/refund and balanced journals. Lost HTTP acknowledgement after finalization is regression-tested without double invoicing.

Web/native now select CSMS start/stop for linked records, hide manual meter overrides, show source-wait states and display read-only simulated invoice summaries. Existing unbound simulator flows remain supported. Backend list metadata is additive and tenant-scoped.

Current local verification: Laravel 85 / 5,532 assertions; native analyzer clean, 24 unit/widget passes plus one explicit default live skip, separate real-Laravel native integration 1 pass; three Chromium suites pass. PostgreSQL finance 14 / 4,999 assertions and cross-invoice payment/refund races 2 / 26 assertions pass. CI now runs the consumer/restart and PostgreSQL suites. Evidence: fullstack-* and source-consumer-*. The inherited native source 0fe9e75 additionally passed hosted Android debug/iOS simulator builds and 1 iPhone-simulator TLS/Keychain/UI test (run 40469622903); updated native screens require their own hosted build result.

Still required: safe provision/dispatch/reconciliation workers, connector-level inventory/reservations/company maintenance gates, full vendor/staff/KYC, OCPI and finance UI, and all commercial subscription/settlement/loyalty/map scope. Real hardware, PSP/roaming partners, legal KYC/tax and signed store/device/staging/operational acceptance remain separate. This remains an unfinished commercial platform, not a final release.


## Verified full-stack merge

Branch **development/fullstack-continuation** preserves backend b0c43be and frontend through 0fe9e75 with merge 6156600. No original branch was reset or force-pushed. Canonical client remains apps/mobile. The only merge conflicts were documentation; backend and frontend behavior are both retained. The integrated checkpoint also fixes charging setup when the event-key file exists before Hasura setup, and makes the PostgreSQL finance immutability function repeatable for migrate:fresh. Existing keys/data are preserved.

Executed: Laravel **82 tests / 5,477 assertions**; Flutter analyzer and **21 passed / 1 explicit live-only skipped**, separate actual-Laravel native integration **1 passed**; all three Chromium suites passed. Legacy OCPP transport, full protocol sessions and correlated business/invoice/payment/refund simulations passed for both 1.6 and 2.0.1. PostgreSQL finance **14 tests / 4,999 assertions** and synchronized cross-invoice payment/refund concurrency **2 tests / 26 assertions passed** in an isolated disposable database. Immutable storage and duplicate-posting assertions remain enforced. Evidence is docs/evidence/fullstack-*. No physical hardware, real PSP, roaming partner or production acceptance is claimed. Native device TLS/Keychain CI remains a separate unfinished check until a run passes.

Next code: durable continuous source-event consumption using authenticated documented CSMS APIs and the signed inbox; then linked-session routing and mounted finance/vendor/OCPI client workflows, connector-level reservation gates and all remaining commercial scope. PostgreSQL concurrency is now locally verified for the tested cases; load, isolation and production acceptance remain separate.


**NOT READY FOR FINAL ACCEPTANCE.** The full commercial requirements remain mandatory. This report concerns the integrated source checkpoint containing this file, based on frontend commit `92a8c8b`; historical evidence in Git remains revision-specific.

## Current executed evidence

| Check | Result | Evidence |
|---|---|---|
| Combined Laravel tests | 82 passed / 5,479 assertions | `evidence/universal-backend.txt`; latest log is authoritative |
| Real-backend browser | Passed | `evidence/universal-browser.txt` |
| Isolated web contracts | Passed | `evidence/universal-frontend.txt`; responsive, safe rendering, errors and pagination |
| Web EN/AR/RTL | Passed | `evidence/universal-localization.txt` |
| Canonical Flutter analyzer + tests | Clean;21 passed,1 explicit live-only skipped | `evidence/universal-native.txt` |
| Native protocol against running Laravel | 1 passed separately | `evidence/universal-native-live.txt`; no physical device claim |
| Full OCPP1.6/2.0.1 sessions | Both passed | `evidence/charging-protocol-sessions.txt` |
| CSMS → business → finance | Both passed | `evidence/charging-business-sessions.txt` |
| Strict Composer manifest/lock and advisory scan | Executed; inspect report | `evidence/universal-composer-audit.json` |
| npm advisory scan | Executed; inspect report | `evidence/universal-npm-audit.json` |
| PHP formatting | Checked with Pint; affected style failures fixed | Recheck current source before pushing |
| PostgreSQL migration/concurrency | Not locally verified | Isolated runner under `tests/Postgres`; readiness/storage failures are not passing tests |

The protocol tests use actual running CitrineOS/PostGIS/RabbitMQ/Hasura and simulated chargers. They verify accepted authorization, start, cumulative energy, reconnect, stop and persisted fault/recovery. Both reconcile exactly2.5kWh. OCPP2.0.1 repeated sequence produced an extra upstream periodic meter row; the report records it rather than claiming upstream perfect dedup. Billing uses cumulative register delta and the business inbox deduplicates event/sequence identity.

The business test uses actual authenticated Laravel HTTP, an explicitly labeled development-only current-slot reservation fixture, command outbox and documented CSMS GraphQL transaction/meter observations. A controlled test adapter signs and forwards those observations. It verifies one invoice329AEDminor, exact replays, conflict rejection, one simulated payment/refund and balanced ledger. It is **not** a continuously running production consumer, a physical charger, a PSP sandbox/live payment or a legal tax invoice. Public reservation behavior is covered by separate browser/API tests.

Finance, vendor-ops and OCPI routes are now mounted in the application. Security regressions include wrong-tenant access, manual override prevention for CSMS-linked meters/stops, signature freshness/authentication, sequence/event identity, meter monotonicity, finalization/replay, encrypted evidence, private notes and immutable accounting. Finance review also corrected numeric form tariff casts and unique-key race recovery. PostgreSQL tests must still pass before PostgreSQL-specific claims.

## Preservation and resource recovery

Fetched all remote heads/PRs, retaining both accounts' work. Local source/hash manifest and Git bundle were verified before integration. Newer verified frontend resolved conflicting interrupted web drafts; canonical `apps/mobile` supersedes local parallel native drafts, which are retained outside checkout. No force push, production reset or existing-data deletion occurred.

A disposable PostgreSQL image attempt filled disk and triggered sandbox startup failures. Only task-generated downloads/caches/test images were reclaimed; application/CSMS data and source archives were preserved. Reproducible SDK and Gradle caches were moved to temporary storage with path links. After recovery, affected tests were rerun. Temporary toolchain caches are not durable release artifacts or proof of snapshot restoration.

## Open gates

Full client/business scope, continuous source ingestion, full OCPI and PSP adapters, subscriptions/loyalty/settlement/fleet billing, connector bookings, 2FA/privacy, formal localization/accessibility/premium design, native recovery, notification delivery, PostgreSQL concurrency, image/SBOM/security/load/restore/staging and all signing/store/hardware/partner/legal gates remain open. Staging configuration in `infra/staging` has not been built/deployed/accepted here. Existing hosted native artifacts are debug/unsigned simulator outputs for the source revisions in `docs/FRONTEND.md`, not complete customer/vendor release packages.

No `100% COMPLETE`, `PRODUCTION_READY` or `SOFTWARE BUILD COMPLETE` claim applies. Next implementation and exact commands: [UNIVERSAL_CONTINUATION.md](UNIVERSAL_CONTINUATION.md).

## Full-stack continuation merge — 2026-10-27

Continuation branch: development/fullstack-continuation, created from backend b0c43be. Merged the complete latest frontend history through 0fe9e75, including the three commits through 95ca47e plus the subsequent PKCS12 fixture/diagnostic correction. Both histories and apps/mobile are preserved; original branches are unchanged. Source files merged without conflict. Documentation combines current backend evidence with frontend work rather than replacing either implementation.

Added frontend scope includes read-only scoped vendor/admin command history with pagination/EN/AR/retry/private-view cleanup, streamed-response session generation checks and stronger secure-storage/TLS regression tests. Prior frontend local tests passed 21 unit/widget tests (1 default live skip) plus the separate Laravel protocol integration. Android debug/iOS simulator builds passed at 95ca47e; device TLS/Keychain acceptance is still under investigation and is not claimed passed. The integrated Laravel/OCPP/finance/OCPI/browser/Flutter suites must now be rerun; inherited evidence remains revision-specific.

## Charging CI 429 correction

The hosted continuous-session test exhausted the existing per-account budget while polling. The synthetic integration client now respects a bounded Retry-After once for a received 429, snapshots the identical serialized request body, and never retries transport uncertainty or 503 command outcomes. Poll cadence is reduced. Production rate-limit configuration is unchanged. Focused request tests cover immutable payload, repeated/invalid limits and no uncertain-command replay; both continuous protocol/restart/invoice flows pass locally. Hosted rerun is required for the new revision. Evidence: charging-rate-limit-contract.txt and charging-rate-limit-protocols.txt.

## Reconnect and hosted simulator diagnostics

The rate-limit correction passed hosted platform run 40476705397. A parallel run exposed the upstream interval between isOnline=false and socket-registry cleanup. Protocol tests now retry only rejected reconnect handshakes within 12 seconds, then still require accepted boot, heartbeat, stop, final energy and persisted fault recovery. Initial connection and all charging commands are unchanged. Both local protocol tests pass; server rate limits and charger command replay rules remain intact.

Hosted native builds passed, but some iPhone runner attempts timed out before the Dart test began. CI now records Xcode/runtime selection, prefers an installed iOS 18 simulator when available, bounds Flutter launch below the job timeout and retains verbose/screenshot/Runner diagnostics. No certificate or test assertion is bypassed. New device-run results must be recorded separately.
