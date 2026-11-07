# Agent 2 / Agent 1 — verified Express/OCPP customer integration contract

This file documents **staged, not deployed** code from PR #50. It is not a claim that
the Laravel/CitrineOS platform or the connected physical Teltonika has been commissioned.

## Business ownership boundary

- **Agent 1** owns the canonical Laravel/PostgreSQL/CitrineOS charging and finance stack,
  customer/vendor mobile/web, cross-role demonstrations and live PSP integrations.
- **Agent 2** owns this separate, existing Express/Mongo customer-client integration proof
  against the already deployed `api.hasone.io` API and OCPP gateway.
- A paid booking, authorized charger operation and metered session must never produce
  two invoices or two merchant settlements in different backends.
- **Never** treat source `billingStatus: calculated_unpaid` as collected funds or vendor earnings.
- Hardware OCPP start/stop remains disabled without owner-approved commissioning.

## HTTP contract proved in the isolated Express test app

Customer JWT required unless marked public:

| Method | Route | Verified purpose |
| --- | --- | --- |
| GET | /health | Database health (public) |
| GET | /api/v1/app/settings | Disclose safe currency/payment toggles (public); unsupported saved-card disabled |
| GET | /api/v1/NearestStations | Authenticated station discovery |
| GET | /api/v1/StationDetails/:id | Authenticated station info |
| POST | /api/v1/MyVehicles | Customer garage registration with active model |
| POST | /api/v1/Bookings | Date/slot booking with atomic duplicate rejection |
| POST | /api/v1/Bookings/:id/payment | Provider-bound payment contract; **not live PSP accepted** |
| GET | /api/v1/ChargingSession/:id/status | Read source-backed metering bound to customer booking/charger/token, no mutation |
| POST | /api/v1/ChargingSession/:id/start | Reject while no verified canonical hardware start adapter |
| POST | /api/v1/ChargingSession/:id/stop | Reject while no verified canonical hardware stop adapter |
| GET | /api/v1/payment/status/booking/:id | JWT and record-ownership enforced |
| POST | /api/v1/admin/bookings/:id/ocpp/issue-token | AdminAuth: create one scoped, expiring charger token for paid+arrived booking |
| POST | /api/v1/admin/bookings/:id/ocpp/bind-source | AdminAuth: bind exact OCPP source transaction after verification |
| GET | /api/v1/admin/ocpp/booking-sessions | AdminAuth: linked OCPP kWh and calculated but unpaid AED report |

**Server-generated test data:** The isolated suite seeds a dummy paid+arrived booking
solely to exercise authorization and OCPP linkage. This is NOT a real payment.

### Example customer source-backed status fields

```json
{
  "chargingStatus": "Completed",
  "sourceVerified": true,
  "meterVerified": true,
  "source": "ocpp",
  "protocol": "ocpp1.6",
  "energyWh": 5000,
  "kwhUsed": 5,
  "batteryPercent": null,
  "timeRemaining": null,
  "paymentStatus": "not_collected",
  "canStop": false,
  "canExtend": false
}
```

Real charging evidence is shown only when the session is bound to an approved charger,
the booking has a verified source token, the transaction ID and charger ID match,
and meter values agree with the source. No estimated battery percentage is presented
as a physical charger reading.

### Example Admin unpaid session report fields

```json
{
  "currency": "AED",
  "billingMode": "calculation_only_no_collection",
  "totalCollectedAed": 0,
  "entries": [{
    "energyKwh": 5,
    "calculatedUnpaidAed": 5.25,
    "paymentStatus": "not_collected",
    "meterVerified": true
  }]
}
```

Do **not** enable Stripe, SSLCommerz or raw-card settlement without verifying
signed production/sandbox provider events, amount and currency, customer ownership,
idempotency and actual merchant capture. The staging patch hides and blocks the
original direct card flow and raw PAN collection.

## Reproduce

Run these inside a disposable source copy on the authorized server (not a
production directory):

```sh
python3 tools/agent2/stage_payment_safety.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_booking_slot_uniqueness.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_charging_source_safety.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_ocpp_customer_status.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_admin_ocpp_binding.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_google_maps_config.py STAGED_SOURCE_COPY
python3 tools/agent2/stage_ocpp201_token_binding.py STAGED_OCPP_HANDLERS_COPY

node tools/agent2/run_isolated_backend_demo.mjs \
  STAGED_SOURCE_COPY EXISTING_EXPRESS_DEPENDENCY_ROOT STAGED_OCPP_HANDLERS_COPY
node --test integrations/hasone-express/tests/*.test.mjs
```

The staged runner binds both HTTP and MongoDB exclusively to `127.0.0.1`,
uses an ephemeral database, and removes it after testing. It does not issue
commands to the connected physical charger.

## Remaining production dependencies

- Signed actual PSP sandbox success/failure/webhook scenarios; bank settlement
  and refund reconciliation, no raw card storage.
- Authorized physical charger full admission/start/meter/stop/QA.
- One chosen canonical billing and settlement authority between the two backends.
- Real vendor app and Admin parity against that authority (Agent 1).
- Approved OCPI live roaming credentials/agreements.
- Verified offsite Mongo recovery and approved deployment/rollback.
- Google Maps server key rotation/restriction plus environment deployment configuration.

**Current status:** This is an isolated integration implementation, not a deployed
release or complete production acceptance.
