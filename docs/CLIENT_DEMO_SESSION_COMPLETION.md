# Agent 10 — read-only evidence after a *local synthetic* charging demonstration

The existing EV client-demo scripts create synthetic customer/vendor/Admin accounts, two simulator-only OCPP 1.6 and 2.0.1 stations, and isolated local CSMS source traffic. This **post-demo report** checks the results of charging scenarios that have **already been performed manually** on the approved local development environment. It does not reserve a slot, dispatch a start/stop, issue a card authorization, contact a physical charger, process a PSP payment, change a financial ledger or send events to production.

## Safe operator workflow

Use an isolated local Laravel app with SQLite, mock payments and a synthetic-only CitrineOS connection. Normal demonstrations must create reservations through the applications for future slots; **do not bypass booking windows for customer acceptance**. Following an authorized two-protocol simulator-only demonstration in which both sessions have fully ended and produced positive simulated energy:

```sh
umask 077
mkdir -p -m 700 artifacts/client-demo/private-evidence
node scripts/client-demo-session-evidence.cjs \
  --manifest artifacts/client-demo/EXACT_RUN/access.json \
  --output artifacts/client-demo/private-evidence/completed-UNIQUE-ID.json
```

The script accepts only the canonical private `access.json` manifest with three `example.test` accounts and nonphysical stations. It connects only to `http://127.0.0.1:8000` and sends CSRF/login/logout plus authenticated **read-only GET** requests to `/api/reservations`, `/api/simulations`, `/api/finance/invoices`, and the two exact numeric invoice-detail endpoints. It never calls `simulate-payment`, provider checkout, refund, settlement or a charging start/stop endpoint. It uses a separate synthetic customer session, always attempts logout even if the response reports the wrong identity, and verifies the session is revoked. It never prints an email, password, account ID, reservation, transaction ID, session cookie or raw API body.

For each distinct fixture station, it requires a customer-owned reservation and its **separate, stopped, CSMS-linked** source session, with `evidence=SIMULATED_CSMS`, `payment_status=not_processed`, nonnegative simulated amount and positive bounded simulated watt-hours. Each completed protocol is recorded separately; a single session or incomplete/active transaction can never prove both protocols. Each source session must also have its own customer-owned **SIMULATED** invoice, matching simulated energy and currency, whose read-only finance summary reports `payment_provider=SIMULATOR_ONLY`, `real_payment_processed=false`, a balanced journal, zero payments/refunds and zero net collection. The resulting report contains only protocol-level booleans: **no session IDs, invoice IDs, customer email or financial amounts**.

The resulting new, owner-only JSON evidence contains only the two protocol labels, boolean results, UTC observation window, source `LOCAL_SYNTHETIC_ONLY` and **explicit false** claims for live physical charging, real money movement, contractual or production acceptance. The output is never overwritten. A missing response, stale pagination (only first 25 visible), broken logout or incorrect source provenance yields `INCOMPLETE`, not a misleading report.

The tool does **not** prove that the CSMS source actually contacted an independently controlled network or that a payment was settled. Such claims require separate source/network evidence and operator verification. Always keep account manifests private and discard synthetic test accounts when the authorized lab session finishes.

## Exact-source test command

```sh
node --check scripts/client-demo-session-evidence.cjs
node --test tests/client-demo-session-evidence.cjs
```

The isolated Node test suite includes positive and negative session linkage, both protocols, no-payment evidence, customer reservation scope, nonboolean physical claims, failed/forged login cleanup, rejected stale or incomplete results, new-only private evidence and no network calls to external providers. Hosted CI, a genuine isolated two-protocol Laravel/CitrineOS run and customer acceptance must be verified independently.
