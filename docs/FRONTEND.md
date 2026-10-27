# Frontend implementation and continuation

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


## Current frontend continuation — 2026-10-27

Implemented native paginated fleets/invitations, explicit acceptance, owner-only invitation/revocation and cost centers, own-vehicle assignment across vehicle pages, and member-scoped detail. Vendor simulator session pages now edit cumulative Wh with monotonic validation, retain input after conflicts and confirm stop. All new screens use shared English/Arabic resources and existing backend authorization/contracts.

Opt-in HTTPS session persistence uses flutter_secure_storage 9.2.4 (iOS device-only unlocked Keychain; Android encrypted preferences/Keystore with backups disabled). Only secure, HTTP-only, unexpired server cookies are stored, bound to origin and capped at 24 hours per snapshot. Password/CSRF/account data are not persisted. Restore requires fresh /me and CSRF; expiry/rejection clears private views. Serialized vault operations prevent late saves after logout. Storage failure has no plaintext fallback; deletion failure does not falsely report logout.

Web English/Arabic resources cover authentication/recovery, catalog, bookings/sessions, garage, fleets, support/inbox and admin regions/metrics/audit controls. Explicit text/attribute bindings preserve typed fields and user content. Language persists locally with RTL and storage-denied fallback. Formal translation/accessibility acceptance remains outstanding.

Vendor and Super Admin web now include **read-only charger command history** from GET /api/charger-commands, with scoped API pagination, refresh/loading/retry states and EN/AR transport-status labels. No command is issued, replayed or automatically reconciled. Submitted/rejected/unknown transport states never claim a charging session, energy delivery or payment. Customer access hides the workspace; 401/419 clear private records. Browser contracts cover these boundaries.

Local verification: Flutter analyzer clean; **21 unit/widget tests passed, 1 explicitly live-only skipped**; separate **1 real-Laravel native integration passed** (now includes fleet membership/privacy/revoke and vendor meter authorization/conflicts/stop); Laravel **37 tests / 279 assertions passed**. Chromium real-backend, frontend-contract and new localization suites passed. Hosted Android/iOS build and native Keychain device-test results will be recorded after CI completes. No backend controller/migration/business rule changed.

Next implementation: native recovery/deep-link consumption using an agreed link contract, richer available-API account/garage workflow and lifecycle QA; full inventory/staff/KYC/finance/map capabilities need Agent 1 API contracts. Fleet API currently cannot re-invite revoked members, edit/delete centers or unassign vehicles; only the acting user's own vehicles can be assigned. No fake controls added for unavailable operations. Physical devices, deployed HTTPS, mail/push delivery, signing/stores, live charger/partner/PSP integrations remain unverified. The platform remains in development, not a final release.


Branch: **development/frontend**. Current application/test source validated at **d454a6875e4eee1af8f6d7c681f861d918df12a8**, [draft PR #2](https://github.com/creabizsalonspa-create/EV/pull/2). Subsequent documentation commits do not change that application source. The full commercial platform remains in development; this is not final owner acceptance.

## Native customer/vendor implementation

- Real server-side catalog pagination, name search, connector/country/minimum-power filters. Applied page/filter state survives tab navigation. Invalid input, loading/empty/error states, clear and exact-page retry are implemented. Stale/out-of-order responses and disposed callbacks are ignored.
- Persistent Light/Dark/System and English/Arabic through shared_preferences. Only theme/locale are stored; credentials stay in memory. Preferences load before production Flutter rendering. Corruption falls back safely; failed writes are visible. Material localization delegates, resource/interpolation parity and logical layouts provide RTL across implemented screens and dialogs.
- Customer/vendor registration/login, validation, profile update, recovery/verification requests, accurate verified status and session-expiry cleanup. Admin remains web-only. Recovery/verification link consumption uses the existing web flow; native deep links and secure persistent authentication remain unfinished.
- Simulator bookings/cancellation/start/stop, paginated reservations/sessions/garage and vendor station creation preserve existing API behavior and evidence labels.
- Paginated support tickets/public message threads, replies/closed-ticket behavior, notifications/read state. Internal notes are never rendered. A stale support 401 cannot clear a later screen.

### Native files

| Path | Responsibility |
|---|---|
| apps/mobile/lib/api.dart | Same-origin cookies/CSRF, encoded query parameters, typed account/station/filter/page models |
| apps/mobile/lib/catalog.dart | Catalog state/request generation, filters, page navigation and retry |
| apps/mobile/lib/preferences.dart | Persistent non-secret appearance/locale and storage failure handling |
| apps/mobile/lib/strings.dart | Aligned English/Arabic resources and translated error classes |
| apps/mobile/lib/support.dart | Ticket/inbox pagination, public threads and read/reply actions |
| apps/mobile/lib/main.dart | Responsive phone/tablet navigation, authentication, settings and other existing workflows |
| apps/mobile/test/ | Unit/widget tests plus opted-in actual-Laravel protocol integration |
| apps/mobile/integration_test/native_flow_test.dart | Native iOS-simulator UI/HTTP/platform-preference fixtures, excluded from product lib |
| .github/workflows/mobile.yml | Pinned SDK, tests, Android/iOS builds, ordinary artifacts, then iOS-simulator UI test |

## Web operations and design

The existing Blade/JS/CSS workspace retains customer/vendor/admin workflows and shared Light/Dark/System themes. The catalog has real API filters/pagination and clear provenance. API/session failures clear private views. Admin metrics/audit rows use actual API data; audit search and CSV export apply only to the latest 50 returned events. CSV quoting/formula-prefix handling is tested. Full historical export needs a backend contract. No backend controller, migration, business rule or CSMS service changed during this frontend batch.

`public/platform.css` semantic palettes: light background/surface/ink/accent #f4f7f8/#ffffff/#153438/#126b63; dark #0c1c20/#142b30/#e5f2f2/#88ded0. Cards 20px, controls 10px, visible focus, minimum touch targets and reduced motion. Native Material 3 uses the same teal seed, adaptive brightness and 20px cards/48px primary actions. Full premium design/accessibility acceptance is still required. Native and web implemented-workflow English/Arabic resources are present; formal translation review remains.

## Prior catalog checkpoint verification (historical)

| Check | Actual result / scope |
|---|---|
| Flutter analyzer | No issues |
| Default native unit/widget suite | **15 passed / 1 explicitly live-service test skipped** |
| Actual Laravel native-client integration | **1 passed**, including 26 station records, real 25+1 pages, combined filters, auth, bookings/privacy/support/inbox/read/cancel/logout |
| Backend regression | **37 passed / 279 assertions** |
| Chromium real-backend + isolated frontend contracts | Both passed; includes admin audit filtering/download and safe CSV content |
| Hosted Android debug APK | Passed and retained, native run 40460073960 |
| Hosted unsigned iOS simulator app | Passed and retained, same run |
| iPhone-simulator UI integration | **1 passed**, same run; native HTTP authentication, page navigation, platform preference reload, Arabic/dark customer/vendor UI using isolated on-device fixtures |
| Hosted platform workflow | Passed, run 40460073970, including existing OCPP simulation checks |

The device fixture test and actual-Laravel protocol test are distinct. They do not prove physical-device/deployed-HTTPS integration. Ordinary application artifacts are archived/uploaded **before** the integration-test app is built. Neither an AAB nor a signed IPA/store release is claimed. Exact head/step/retention metadata and runner output are in docs/evidence/native-catalog-*.

## Reproduce

Use existing /workspace/EV checkout; do not create worktrees unless explicitly requested. Read AGENTS.md, current progress/QA and latest Git/PR checkpoints. Preserve backend work and never force-push.

```sh
export PATH="/workspace/.tools/flutter/bin:$PATH"
export PUB_CACHE=/workspace/.cache/ev-pub-cache
export XDG_CONFIG_HOME=/workspace/.cache/ev-config
export ANALYZER_STATE_LOCATION_OVERRIDE=/workspace/.cache/ev-analyzer
./apps/mobile/tool/bootstrap
cd apps/mobile
flutter test --dart-define=EV_NATIVE_SMOKE_URL=http://127.0.0.1:8000 test/live_api_test.dart --reporter expanded
```

The opt-in test requires the running disposable local Laravel service (`./scripts/start` from root) and rejects remote hosts. It creates synthetic records and uses the operator-only role CLI. Standard web checks from root: EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e; npm run test:frontend with the same browser variable; scripts/php artisan test --compact. See apps/mobile/README.md for SDK pin/build commands. Artifact-log host additions are saved in the environment draft; draft persistence is separate from publication/fresh-task restoration.

## Remaining full-scope work

1. Native recovery/deep links and deeper lifecycle/accessibility QA; richer inventory/staff/KYC once APIs are available. Fleet and vendor simulator tools plus encrypted session persistence are implemented in the current continuation.
2. Maps/authorized live availability, tariffs, invoices/payments/settlements, subscriptions/loyalty/promotions, complete customer/vendor/admin workflows. Do not invent successful integration responses or overwrite Agent 1's backend contracts.
3. Full web English/Arabic resources, formal native/web accessibility/translation/performance review and cross-browser/device matrix; replace development launcher/splash assets with owned branding.
4. Actual phones and authorized HTTPS service testing, real push/mail delivery, signing/store pipelines, partner/hardware/PSP and production acceptance gates. Admin remains web-only. Current simulator and pricing fixtures are never physical charging or processed payments.
