# Agent 10 — read-only, three-role synthetic charging ledger handoff

This is **post-demo evidence only** for the existing local mock/CitrineOS charging lab. It does **not** execute a real charging action, issue an invoice, simulate a payment, move money, authorize hardware, change database state or deploy anything. It requires the exact private local `client-demo` fixture that created the synthetic customer, vendor and Admin accounts.

## Operator sequence (local, isolated test environment)

After an independently approved simulated OCPP 1.6 and OCPP 2.0.1 session has completed, and the canonical application has already issued the associated *simulated* finance invoices:

```sh
umask 077
mkdir -p -m 700 artifacts/client-demo/private-evidence
node scripts/client-demo-session-evidence.cjs \
  --manifest artifacts/client-demo/EXACT_RUN/access.json \
  --output artifacts/client-demo/private-evidence/completion-UNIQUE.json
node scripts/client-demo-ledger-audit.cjs \
  --manifest artifacts/client-demo/EXACT_RUN/access.json \
  --output artifacts/client-demo/private-evidence/ledger-UNIQUE.json
```

The two tools have different duties: `client-demo-session-evidence.cjs` checks two completed *source-linked* simulator sessions, positive simulated energy, two matching simulated invoices and zero recorded payment/refund. `client-demo-ledger-audit.cjs` independently checks their **actual double-entry account distribution** and customer/vendor/Admin authorization boundaries using read-only API calls.

### Three account boundaries

The verifier authenticates and logs out all three *synthetic* `example.test` identities separately. It requires:

1. Customer — read-only scoped reservations, charging sessions and invoice listing, with **403 denied** on the private `GET /api/finance/invoices/{id}/journal` route.
2. Vendor — exact ownership of both simulated invoices and read-only access to both owned journals, with no foreign invoice in its list.
3. Admin — read-only access to the same two invoices' journals, with complete independent ledger distribution checks.

Each session requires a successful log out and subsequent denied `GET /api/me`. No POST is sent except synthetic login/logout, and the transport is hard-coded by `staging-synthetic-role-smoke.cjs` to `127.0.0.1:8000` with the fixed, reviewed route allowlist. Redirects, nonfixture credentials and unexpected HTTP methods fail closed.

### What is financially verified

For each of the two source-linked simulator sessions the exact `SIMULATED` invoice posting must have a unique `invoice` journal, with the expected currency and amount. Every debit and credit is checked against the canonical mock-finance posting formula in `FinanceService.php`:

| Account | Debit (minor units) | Credit (minor units) |
| --- | --- | --- |
| `receivable:user:<synthetic customer ID>` | Full simulated invoice total | 0 |
| `vendor_payable:<synthetic vendor ID>` | 0 | Simulated subtotal minus commission |
| `platform_commission` | 0 | Simulated commission |
| `tax_payable:AE` | 0 | Simulated tax |

Zero-value entries must be omitted. Debit must equal credit and must equal the invoiced total. The audit rejects any duplicate, unexpected account or cash/PSP posting, wrong owner, wrong currency, invalid source link, unrelated journal, broken sum, missing session or leaked journal permissions.

### Evidence boundaries

The resulting new private, owner-only `*.json` is non-overwriting. It contains only a yes/no state, both synthetic protocol labels, the number of balanced invoice postings, a UTC check window and **false** for physical charging, real money movement, payment-provider verification, production acceptance and contractual acceptance. It contains **no** passwords, account IDs, PIDs, raw ledger accounts, invoice amounts, cookies, JWTs, session details or private API response bodies.

Passing this check is **not** evidence that any live payment processor, Teltonika charger, external OCPP/OCPI operator network, production customer account, Android/iOS store build or commercial settlement is operational.

## Tests

```sh
node --check scripts/client-demo-ledger-audit.cjs
node --check tests/client-demo-ledger-audit.cjs
node --test tests/client-demo-ledger-audit.cjs
```

The exact checked-in GitHub source was copied to an isolated authorized Node 24.22.1 environment: **15/15 focused regression tests passed** (0 failed, 0 skipped). Tests used synthetic local fixture records and mocked HTTP transport; **no real Laravel/charger/PSP call was performed**. Full shared Agent 10 test suite and mandatory exact-head hosted CI remain separate integration gates. Agent 4 coordinates the merge sequence; Agent 9 performs independent customer journey QA.
