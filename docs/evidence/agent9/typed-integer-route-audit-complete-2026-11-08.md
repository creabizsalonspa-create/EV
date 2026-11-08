# Agent 9 — Global typed-integer route boundary audit checkpoint

**Date:** 8 November 2026. **Exact source tested:** draft Agent9 PR #64 at commit `b155eb8508ea7098ce3b20741e1e305e31453fae`. Unmerged, no production acceptance.

## Security audit and deliberate exceptions

Earlier batches made invalid oversized positive digit strings fail route matching in critical payment, firmware, charger operation, public catalog, corporate and customer review paths. Following a **source-backed method signature inventory across every Laravel route file**, 57 legacy `whereNumber` constraint occurrences still existed at the earlier `5e4132b4` checkpoint: 51 `int` controller parameters, two dynamically generated hosted-payment actions with `int` parameters, two string-based favorite controller parameters and two OCPI Eloquent model-bound `OcpiRecord` location parameters.

Subsequent batches converted:
- 23 corporate billing / hosted payment / external-dispute occurrences (see exact source `fe539210c7bcb343871eea9e722afe8324d8af67`).
- 30 additional typed-int campaign, loyalty, payment recovery, recurring-payment, rider access, subscription and roaming order occurrences, plus two OCPI location model-bound paths (32 occurrences), culminating at `b155eb8508ea7098ce3b20741e1e305e31453fae`.
- **Only two `whereNumber('station')` occurrences remain across the complete `services/business-api/routes/*.php` directory**: `PUT /favorites/{station}` and `DELETE /favorites/{station}`, each intentionally preserved because `StationFavoriteController::stationId(string)` validates `required|integer|min:1` before casting and promises HTTP **422** for invalid oversized station IDs. A real initial regression caused 404 and was reversed; see `customer-routing-and-favorite-contract-2026-11-08.md`.

Every other historical `whereNumber` occurrence was replaced with an explicit `[0-9]{1,18}` ID matcher. Existing authentication/throttling, tenant policies, source provenance, UUID references, payment action allowlists and provider/client logic were left unchanged. The 18-digit cap also rejects some theoretical valid 19-digit signed 64-bit IDs; reconsider this deliberately if DB identity allocation ever reaches that range.

## Latest commits in final numeric route batch

| Commit | Scope |
| --- | --- |
| `4e5822ec37f3b5fc62d4959cf07bb0ea92ea2c70` | Campaigns: 2 |
| `194ac408996fb56e2827fa8a692814c993a95e99` | Loyalty: 4 |
| `c5170b84637366a7e65be059e956c8221f0a062c` | Payment recovery: 2 |
| `51032c9c08d8ed73d5952af33cdef8588bf90581` | Recurring payment: 6 |
| `8038d785acd71ffaa9512f5fb28e92e3ec92539f` | Rider access: 3 |
| `a0507fa2fbc4ae0d21f9dd476846211158ee58b8` | Roaming sandbox order: 4 |
| `99adc9e9fe966a471ca80415a73688dade1a7050` | Subscription credits: 3 |
| `888a53db86d909920d1c4615357a0408dede6203` | Subscriptions: 6 |
| `87914bd55e241cbf0589494b7c4c49d6ba9361f3` | OCPI location model binding: 2 |
| `00b4c0e2761300fc2e39ea47e93dd3d3fb641415` | Global Node inventory guard |
| `b155eb8508ea7098ce3b20741e1e305e31453fae` | Laravel two-mount route match regression |

## Actual QA execution

**Host:** authorized `host.crea-biz.com`. Detached/sparse disposable worktree `/tmp/ev-agent9-corporate-qa`, exact source at `b155eb85...`, PHP 8.4.24, PHPUnit 11.5.57, Node 24.22.1. Composer autoload `App\\` resolved to the disposable checkout, not the original shared checkout. Test environment: SQLite in-memory, generated random `APP_KEY`, array sessions/cache, sync queue and non-network mocked/sandbox flows. No live charger commands, real money or external partner acceptance.

| Verified test selection | Result |
| --- | --- |
| `RemainingTypedRouteBoundaryTest` | **1 test / 61 assertions PASS** |
| `CommercialCampaignTest|LoyaltyEconomicsTest|PaymentRecoveryTest|RiderAccessTest|SandboxRoamingTest|SubscriptionCreditTest|SubscriptionDunningTest|SubscriptionGatewayTest|SubscriptionRecurringTest|SubscriptionTest|RemainingTypedRouteBoundaryTest` | **97 tests / 1,064 assertions PASS** |
| `node --test tests/agent9-global-numeric-route-inventory.test.cjs` | **3/3 PASS** |
| `node --test tests/agent9-*.test.cjs` | **32/32 PASS** |
| PHP lint of `RemainingTypedRouteBoundaryTest.php` | **PASS** |
| Laravel Pint `--test` on eight final route files plus the new Feature test | **9/9 files PASS** |

Prior corporate/dispute checkout (`fe53921`): **80 Laravel tests / 743 assertions PASS** and **29/29** Agent9 Node security guards PASS, five affected PHP files Pint PASS. Prior payout/subscription/financial document checkout (`9675668`): **42/527** related Laravel tests PASS, **9/93** additional settlement tests PASS, and **22/22** Node guards PASS. Prior customer-review/favorites checkout (`de1ce2a`): **17/209** Laravel PASS, **26/26** Node PASS and five files Pint PASS. See the dated scoped QA reports for exact isolation/evidence.

### Known integration limits

- **Full latest Laravel Feature suite NOT rerun**: shared QA host /tmp filesystem was near inode exhaustion. The earlier PR64 full Feature run on an older source executed **593 tests / 8,333 assertions / exactly one canonical SQLite settlement precision trigger error**; a disposable local-only `DROP TRIGGER IF EXISTS commercial_adjustments_no_update` probe made that earlier suite **593 tests / 8,335 assertions / PASS** and was reverted, leaving canonical finance owner Agent1 responsible for the real fix. Do not transfer that full-suite green result to this newer source SHA.
- PR64 is **draft and unmerged**. Agent4 owns merge/integration and CI conflict resolution, preserving stronger concurrency, queue and integration-readiness gates from PR57 plus Agent9's mandatory standalone security guard stage.
- Hosted Platform, Finance, Native checks, PostgreSQL migration/concurrency, web/native actual integrated releases, live OCPP chargers, PSP, partner permissions and legal/store acceptance remain unverified at this source checkpoint. No overall product completion uplift or production-ready claim is warranted.

## Subsequent scope correction — unconstrained routes require a separate audit

The 57-route inventory documented above examined **existing `whereNumber()` declarations**, not every URI parameter in the full application. Therefore the statement that only two `whereNumber()` exceptions remain is **not proof that every controller `int` parameter is bounded**. In a later source review, Agent 9 discovered a separate `POST /finance/refund-requests/{request}/review` route that had **no numeric matcher at all**, despite `CommercialFinanceController::review()` accepting `int $request`. This gap has now been fixed with `->where('request', '[0-9]{1,18}')` and regression tests in commits `00b07893`, `ac11044a`, `057afa09`. See `docs/evidence/agent9/refund-review-int-route-boundary-2026-11-08.md` for evidence and pending runtime gates.

A subsequent **sampled** direct-controller review of finance, hosted-payment, corporate, loyalty, subscription, rider, charging and review routes found no additional uncovered direct `int`-typed path parameter in inspected files. This sampled review is not a complete automated reflection audit, does not cover dynamic route declarations exhaustively, and has not been executed as a full Laravel route test on the newest head because the authorized host was offline. Avoid claiming a comprehensive all-controller `int` route guarantee until a full registered route reflection audit and PostgreSQL runtime validation pass.
