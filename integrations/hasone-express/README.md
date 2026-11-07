# Agent 2: Express/Mongo payment-integrity staging patch

**Status: staged and tested, NOT deployed.** This is exclusively for the owner's separately deployed Hasone V-CHARGE Express/Mongo application, not for the Laravel/CitrineOS application.

## Verified problem in the *live* deployed source (2026-11-07)

Inspected `/home/hasio/ev-deployment/admin/UV Charging/src` through authorized Desktop Commander. The deployed code allowed unverified payment-return paths to alter booked/charging payment state, accepted an unsafe Stripe webhook verification fallback, exposed payment-status polling without authenticating ownership, accepted legacy direct-card paths without evidence from a PSP, and included direct raw card storage.

These are important blockers to a complete, trustworthy customer → vendor → Admin charging-and-invoice demonstration. No payment callback mutation was exercised against live production during this inspection.

## Implemented

- `paymentIntegrity.mjs`: pure fail-closed matching of verified PSP proof, server-created transaction/PaymentIntent, record identity, amount, currency, pending state, and user ownership.
- `tests/paymentIntegrity.test.mjs`: 13 pass/fail regression tests, runnable under Node 22 with no external packages.
- `tools/agent2/stage_payment_safety.py`: preflighted source-level hardening of `app.js`, `paymentRoutes.js` and `userService.js` on a disposable copy. Refuses canonical production directories, rejects changed/unrecognized source patterns before writing, captures raw Stripe request bytes before JSON parsing, rejects unsigned webhooks, gates SSL IPN settlement on verified PSP response/amount and server identity, restricts payment status to record owner, and prevents unsupported legacy card settlement/raw PAN storage.

**Verified on the real host:** The script was run on copies of the three deployed source files under `/home/hasio/agent2-work/src-snapshot-20261107`. All three modified files passed `node --check`, and 13/13 guard tests passed on `host.crea-biz.com`. Live source and processes were not modified or restarted.

## Safe reproduction

Copy the authorized current Express `src` source to a disposable directory **outside** `/home/hasio/ev-deployment`, then from the repo root:

```bash
python3 tools/agent2/stage_payment_safety.py /tmp/ev-express-source-copy
node --check /tmp/ev-express-source-copy/src/app.js
node --check /tmp/ev-express-source-copy/src/routes/paymentRoutes.js
node --check /tmp/ev-express-source-copy/src/services/userService.js
node --test integrations/hasone-express/tests/paymentIntegrity.test.mjs
```

The staging patch only applies to a matching Express source revision. It does **not** run any Stripe or SSLCommerz transaction, create an OCPP session, or modify the financial database.

## Before deployment (mandatory)

1. Take and verify an encrypted/restorable MongoDB backup, confirm deploy rollback and audit record. The existing source snapshot alone is insufficient.
2. Test actual signed Stripe webhook raw payloads with a configured webhook secret and authorized sandbox account.
3. Verify actual SSLCommerz SDK validation response fields and amount/currency behavior with authorized sandbox notifications. An empty/unconfigured provider correctly fails closed.
4. Run route-level authenticated ownership and sandbox invoice tests; cover duplicate webhooks, retries and out-of-order events. Add idempotent acknowledgement and atomic settlement where necessary.
5. Replace legacy raw-card collection with PSP tokenization and review any preexisting stored PAN according to your payment compliance obligations. The staging patch **disables** unsupported direct card workflows; update mobile UI/payment-method displays accordingly.
6. Do not turn on OCPP remote controls, live billing or actual charging without independent source and hardware acceptance.
7. Agree with Agent 1 on one canonical charging and billing authority before syncing Express/Mongo data into Laravel/CitrineOS.

This PR is intentionally draft pending authenticated sandbox acceptance and approved deployment controls.
