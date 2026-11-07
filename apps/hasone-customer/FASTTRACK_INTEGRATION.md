# V-CHARGE Flutter fast-track integration

Based on the supplied `mobile_app.zip`. This source has not yet been compiled or
installed on Android/iOS in this workspace. It is **not** a completed app release.

## Live backend connection

The mobile API origin now defaults to `https://api.hasone.io`. Every existing
request path already begins with `/api/v1`, so do **not** include `/api/v1` in
`EV_API_BASE_URL`. The deployment has been verified from `host.crea-biz.com`:
`https://api.hasone.io/health` returns HTTP 200 and connected MongoDB.

```bash
flutter pub get
flutter run --dart-define=EV_API_BASE_URL=https://api.hasone.io
flutter build apk --debug --dart-define=EV_API_BASE_URL=https://api.hasone.io
# For an iOS simulator on macOS:
flutter build ios --simulator --dart-define=EV_API_BASE_URL=https://api.hasone.io
```

The app intentionally rejects non-HTTPS origins. Android cleartext traffic is
disabled; API request/response bodies and credentials are no longer logged.
Stripe keys must be obtained from the backend at runtime, never placeholders.

## Verified, read-only server checks

- `GET /health` -> 200
- `GET /api/v1/app/settings` -> 200
- `GET /api/v1/NearestStations` without authentication -> 401 (expected)
- `GET /api/v1/VehicleBrands` without authentication -> 401 (expected)
- `GET /api/v1/payment-methods` without authentication -> 401 (expected)
- Existing mobile `/api/v1` endpoint shapes are declared by the live Express app.

Authenticated customer login, actual device UI, Maps, payment and charging
journeys still require controlled end-to-end testing. No test user was created.

## Live charger safety

One OCPP 1.6 charger is provisioned and was online in the gateway database.
Remote control is disabled and no OCPP sessions or commands have been recorded.
Do not enable remote control or issue charging commands without device-owner
authorization, safe hardware procedures and verified source session linkage.

## Build/release cautions

- Package ID is still `com.example.evcharg` and Android release signing is
  configured with debug keys. Replace before distributing any production app.
- Google Maps key in the supplied AndroidManifest.xml must be restricted/rotated
  and replaced with your authorized mobile-app key before release.
- External payment approval and device-based charger acceptance are outstanding.
- Maintain separation from the Laravel/CitrineOS repository; integrate at API
  boundaries only until a single canonical charging/billing authority is chosen.
