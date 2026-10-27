# Frontend continuation checkpoint

2026-10-27 · branch `development/frontend` · base `f4feac7` (the separately pushed, verified identity/theme checkpoint). The commit containing this document identifies this frontend implementation. No backend controller, migration, business rule, OCPP service or existing API contract changed on this branch after that base.

## Web implementation and design system

`services/business-api/resources/views/platform.blade.php` retains the established functional controls and same-origin Laravel session architecture. The workspace adds desktop side navigation/mobile horizontal navigation, skip link, role-scoped entries, card-based catalog, location disclosure, truthful source/unknown-availability badges, loading and offline states, filter-preserving catalog pagination and explicit retry. No mock inventory is shipped. The administrator uses real overview counts and audit events; fleet members can assign their own vehicles to same-fleet cost centers through existing endpoints. Backend authorization remains authoritative.

`public/client.js` centralizes credentials, CSRF, JSON/error handling and a 20-second fetch deadline with no automatic mutation replay. Source JSDoc defines Account/Station/Page contracts. Catalog requests abort superseded searches. Session expiry clears private lists, account identity and private forms. Recovery is explicit; no failed payment or charging action is replayed automatically. Server-returned/user-entered content uses textContent rather than HTML injection.

Design tokens in `public/platform.css`:

| Token | Light | Dark | Use |
|---|---|---|---|
| Background | #f4f7f8 | #0c1c20 | workspace canvas |
| Surface | #ffffff | #142b30 | cards, fields, dialogs |
| Ink | #153438 | #e5f2f2 | primary text |
| Muted | #52686b | #b2c8ca | supporting text |
| Accent | #126b63 | #88ded0 | primary actions |
| Accent ink | #ffffff | #0c302c | action text |
| Focus | #b85600 | #ffc66b | keyboard focus |

Typography uses the device system sans stack without third-party font requests. Card radius 20px; control radius 10px; touch targets at least 44px; restrained shadows; logical spacing supports RTL. Shared theme initialization runs before CSS. Reduced-motion preference removes transitions. This design is an owned foundation, not formal acceptance of a complete premium design system. Arabic remains partial/bilingual; a complete resource-based translation and native localization pass remain required.

## Verified versus unfinished

| Surface/module | Evidence/state |
|---|---|
| Customer/vendor web account, catalog, bookings, simulator, vehicles, fleet, support | Existing real backend browser flows passed, now within the updated workspace |
| Catalog filters/pagination/service errors/offline/role navigation | Dedicated isolated frontend contract test passed; fixtures are confined to tests |
| Admin counts/audit | Real backend endpoint rendered as metric cards/table; tested via admin browser flow |
| Themes, responsive/RTL | Chromium tests passed; light desktop and dark mobile screenshots visually inspected locally |
| Web maps, real availability, route navigation | Missing; no map tiles, location consent, live source or partner contract claimed |
| KYC/staff/multi-connector/tariffs/finance/payment/subscription/loyalty/ads | Backend capabilities incomplete; frontend remains unfinished, without invented successful actions |
| Native Android/iOS | Source/platforms/lock present; local analyzer + 3 tests passed; hosted Android debug and unsigned iOS simulator builds passed; device integration incomplete |
| Admin native | Deliberately absent per project requirements |
| Complete English/Arabic, native persistent settings, accessibility and cross-browser certification | Incomplete/unverified |

## Test and build commands

From `/workspace/EV`, start the repository app using `./scripts/start` after `./scripts/setup` and `npm ci --cache /tmp/ev-npm-cache`.

```sh
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:e2e
EV_CHROMIUM_PATH=/usr/bin/chromium npm run test:frontend
./scripts/php artisan test --compact
./scripts/php vendor/bin/pint --test
./scripts/php /usr/bin/composer validate --strict
```

Browser tests must target disposable development data. The contract suite intercepts only its own browser requests and labels fixtures; it is not a data adapter. The main suite exercises actual account/tenant/API behavior and includes a simulated expired-session response to verify DOM cleanup. Both scripts preserve failure exit status. The web UI uses checked-in public assets; no Vite build is needed for this surface. No hosted CI run is claimed.

Native bootstrap/build commands and exact limitations are in `apps/mobile/README.md`. The original metadata failure was resolved using pinned official Git source bootstrap with normal TLS verification. Flutter 3.35.7/Dart 3.9.2 is installed locally; analyzer and 3 tests passed. Generated Android/iOS platforms and lockfile are retained. Hosted run 40454501304 built Android debug and unsigned iOS simulator applications from a547379; no signing, store delivery or authenticated device workflow is claimed. Later artifact-retention checks must establish retrievable outputs.

## Exact next frontend tasks

1. Retain and verify CI Android debug/iOS simulator artifacts for the platform-source revision. Configure an authorized HTTPS development deployment, test native authentication and business flows on actual devices, and extend meaningful widget/integration coverage; current tests cover only the client protocol and unavailable-service state.
2. Extend the native client to full catalog pagination/filtering, verification/recovery, fleet/notifications/support threads, persistent secure session/theme/locale settings and Arabic. Then run actual authenticated device integration against an authorized HTTPS development deployment. Keep admin web-only.
3. Extract complete English/Arabic resources, translated validation and dynamic strings; finish screen routing, semantic/icon/a11y review and Firefox/WebKit/mobile matrix. Add supported map data/consent and truthful station detail capabilities.
4. Coordinate the connector inventory, role/staff/KYC and CSMS session lifecycle contracts with backend work. Add finance/commerce/loyalty pages only as actionable APIs become available; never treat a placeholder screen as a delivered module.
5. Merge/rebase safely after the identity checkpoint is integrated, inspect latest development/backend history before shared-file edits, and run affected tests. Never force-push.
