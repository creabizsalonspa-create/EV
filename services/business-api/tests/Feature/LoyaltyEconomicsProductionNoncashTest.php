<?php

namespace Tests\Feature;

use App\Http\Controllers\LoyaltyController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Commercial\Loyalty\NoncashActivation;
use App\Services\Commercial\Referrals\ReferralRegistry;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Isolated configuration/domain tests, not a deployed production activation. */
class LoyaltyEconomicsProductionNoncashTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['loyalty.enabled' => true]);
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        $s = app(LoyaltyService::class);
        $s->publish('loyalty_policies', $f['maker'], ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'noncash-policy');
        $f['reward'] = $s->publish('loyalty_rewards', $f['maker'], ['currency' => 'AED', 'name' => 'Synthetic manual noncash benefit', 'name_ar' => 'مكافأة يدوية تجريبية', 'description' => 'Manual fulfillment only; no money or delivery proof.', 'description_ar' => 'مكافأة يدوية غير نقدية لا تثبت التسليم', 'points_cost' => 80, 'stock' => 10, 'per_user_limit' => 2, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()], 'noncash-reward');

        return $f;
    }

    private function reviewedProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['loyalty.noncash.mode' => 'reviewed_manual', 'loyalty.noncash.approval_reference' => 'SYNTHETIC-UNIT-REVIEW-NOT-LIVE',
            'loyalty.noncash.terms_sha256' => hash('sha256', 'Synthetic reviewed test terms, never a real approval.'), 'loyalty.noncash.fulfillment_reviewed' => true]);
    }

    private function payload(array $f, string $suffix = '01'): array
    {
        return ['code' => 'SYNTHETIC-REVIEW-'.$suffix, 'reward_id' => $f['reward']->id, 'claim_limit' => 5, 'per_user_limit' => 2,
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => 'publish-'.$suffix];
    }

    private function action(User $actor, string $method, array $payload, bool $withService = false): TestResponse
    {
        $request = Request::create('/isolated-domain-contract', 'POST', $payload);
        $request->setUserResolver(fn () => $actor);
        try {
            $args = [$request];
            if ($withService) {
                $args[] = app(LoyaltyService::class);
            }

            return new TestResponse(app(LoyaltyController::class)->callAction($method, $args));
        } catch (HttpExceptionInterface $e) {
            return new TestResponse(response()->json(['message' => $e->getMessage()], $e->getStatusCode()));
        }
    }

    public function test_sandbox_flag_cannot_activate_production_manual_goods_or_points(): void
    {
        $f = $this->fixture();
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame('disabled', app(NoncashActivation::class)->mode());
        $this->action($f['maker'], 'publishCoupon', $this->payload($f))->assertStatus(503);
        $this->action($f['customer'], 'claimCoupon', ['code' => 'SYNTHETIC-REVIEW-01', 'idempotency_key' => 'claim'], true)->assertStatus(503);
        $this->assertDatabaseCount('loyalty_coupon_claims', 0);
        $this->assertDatabaseCount('loyalty_ledger', 0);
    }

    #[TestWith(['mode', 'enabled'])]
    #[TestWith(['approval_reference', ''])]
    #[TestWith(['terms_sha256', 'not-a-reviewed-terms-hash'])]
    #[TestWith(['fulfillment_reviewed', false])]
    public function test_incomplete_review_configuration_fails_closed(string $field, mixed $value): void
    {
        $f = $this->fixture();
        $this->reviewedProduction();
        config(['loyalty.noncash.'.$field => $value]);
        $this->assertSame('disabled', app(NoncashActivation::class)->mode());
        $this->action($f['maker'], 'publishCoupon', $this->payload($f))->assertStatus(503);
        $this->assertDatabaseCount('loyalty_coupons', 0);
    }

    public function test_explicit_reviewed_manual_mode_supports_canonical_zero_point_fulfillment_without_money_or_point_actions(): void
    {
        $f = $this->fixture();
        $v = $this->payload($f);
        $this->reviewedProduction();
        $cash = DB::table('finance_journals')->orderBy('id')->get()->toJson();
        $coupon = $this->action($f['maker'], 'publishCoupon', $v)->assertCreated()->assertJsonPath('activation_mode', 'reviewed_manual')->assertJsonPath('evidence', 'REVIEWED_MANUAL_NONCASH')->json('id');
        $claim = $this->action($f['customer'], 'claimCoupon', ['code' => $v['code'], 'idempotency_key' => 'claim'], true)->assertOk()
            ->assertJsonPath('claim.activation_mode', 'reviewed_manual')->assertJsonPath('claim.evidence', 'REVIEWED_MANUAL_NONCASH')->assertJsonPath('claim.points_cost', 0)->assertJsonPath('claim.delivery_verified', false)->json('claim');
        $row = app(LoyaltyService::class)->transition($claim['redemption_id'], $f['maker'], 'fulfill', 'Synthetic isolated manual review, not a delivered physical item');
        $this->assertSame('fulfilled', $row->status);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertSame($cash, DB::table('finance_journals')->orderBy('id')->get()->toJson());
        $this->assertSame('reviewed_manual', DB::table('loyalty_coupon_activation_evidence')->where('coupon_id', $coupon)->value('activation_mode'));
        $this->expectException(HttpException::class);
        app(LoyaltyService::class)->earn($f['invoice']->id, $f['customer']->id);
    }

    public function test_listed_discovery_excludes_legacy_sandbox_and_obsolete_review_terms_before_pagination(): void
    {
        $f = $this->fixture();
        $sandbox = $this->action($f['maker'], 'publishCoupon', $this->payload($f, 'SB') + ['listed' => true])->assertCreated()->json('id');
        $this->action($f['customer'], 'discoverCoupons', [], true)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $sandbox);
        $this->reviewedProduction();
        $this->action($f['customer'], 'discoverCoupons', [], true)->assertOk()->assertJsonPath('total', 0);
        $old = $this->action($f['maker'], 'publishCoupon', $this->payload($f, 'OLD') + ['listed' => true])->assertCreated()->json('id');
        config(['loyalty.noncash.terms_sha256' => hash('sha256', 'Synthetic rotated review terms')]);
        $current = $this->action($f['maker'], 'publishCoupon', $this->payload($f, 'NEW') + ['listed' => true])->assertCreated()->json('id');
        $this->action($f['maker'], 'publishCoupon', $this->payload($f, 'PRIVATE'))->assertCreated();
        $response = $this->action($f['customer'], 'discoverCoupons', [], true)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $current)
            ->assertJsonMissingPath('data.0.code_hash')->assertJsonMissingPath('data.0.approval_reference');
        $this->assertNotContains($old, array_column($response->json('data'), 'id'));
        $cash = DB::table('finance_journals')->orderBy('id')->get()->toJson();
        $this->assertDatabaseCount('loyalty_coupon_claims', 0);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $claim = $this->action($f['customer'], 'claimCoupon', ['code' => $this->payload($f, 'NEW')['code'], 'idempotency_key' => 'reviewed-discovery'], true)->assertOk();
        $claim->assertJsonPath('claim.coupon_id', $current)->assertJsonPath('claim.points_cost', 0);
        config(['loyalty.noncash.fulfillment_reviewed' => false]);
        $this->action($f['customer'], 'discoverCoupons', [], true)->assertOk()->assertJsonPath('total', 0);
        $this->assertSame($cash, DB::table('finance_journals')->orderBy('id')->get()->toJson());
    }

    public function test_production_configuration_never_relabels_or_honors_a_legacy_sandbox_coupon_as_reviewed(): void
    {
        $f = $this->fixture();
        $v = $this->payload($f);
        $id = $this->action($f['maker'], 'publishCoupon', $v)->assertCreated()->json('id');
        $claim = $this->action($f['customer'], 'claimCoupon', ['code' => $v['code'], 'idempotency_key' => 'sandbox'], true)->assertOk()->json('claim');
        $this->reviewedProduction();
        $meta = app(NoncashActivation::class)->metadata('coupon', $id);
        $this->assertSame('sandbox', $meta['activation_mode']);
        $this->assertSame('TEST_PROMOTIONAL_ECONOMICS', $meta['evidence']);
        $this->action($f['customer'], 'claimCoupon', ['code' => $v['code'], 'idempotency_key' => 'new-production-attempt'], true)->assertConflict();
        $this->action($f['customer'], 'findCouponReceipt', ['idempotency_key' => 'sandbox'])->assertOk()->assertJsonPath('claim.evidence', 'TEST_PROMOTIONAL_ECONOMICS')->assertJsonPath('claim.id', $claim['id']);
        $this->assertDatabaseCount('loyalty_coupon_claims', 1);
    }

    public function test_review_rotation_blocks_new_claims_but_preserves_original_committed_receipt_and_manual_terms(): void
    {
        $f = $this->fixture();
        $this->reviewedProduction();
        $v = $this->payload($f);
        $this->action($f['maker'], 'publishCoupon', $v)->assertCreated();
        $claim = $this->action($f['customer'], 'claimCoupon', ['code' => $v['code'], 'idempotency_key' => 'original'], true)->assertOk()->json('claim');
        config(['loyalty.noncash.terms_sha256' => hash('sha256', 'A different reviewed terms version')]);
        $this->action($f['customer'], 'claimCoupon', ['code' => $v['code'], 'idempotency_key' => 'different'], true)->assertConflict();
        $this->action($f['customer'], 'claimCoupon', ['code' => $v['code'], 'idempotency_key' => 'original'], true)->assertOk()->assertJsonPath('claim.id', $claim['id']);
        $this->assertSame('fulfilled', app(LoyaltyService::class)->transition($claim['redemption_id'], $f['maker'], 'fulfill', 'Honor the original reviewed manual commitment')->status);
    }

    public function test_legacy_points_cannot_become_production_manual_reward_authority(): void
    {
        $f = $this->fixture();
        app(LoyaltyService::class)->earn($f['invoice']->id, $f['customer']->id);
        $ledger = DB::table('loyalty_ledger')->orderBy('id')->get()->toJson();
        $this->reviewedProduction();
        $v = $this->payload($f);
        $this->action($f['maker'], 'publishCoupon', $v)->assertCreated();
        $this->action($f['customer'], 'claimCoupon', ['code' => $v['code'], 'idempotency_key' => 'blocked'], true)->assertConflict();
        $account = app(LoyaltyService::class)->account($f['customer']->id);
        $this->assertSame(0, $account['spendable_points']);
        $this->assertFalse($account['points_actions_enabled']);
        $this->assertSame($ledger, DB::table('loyalty_ledger')->orderBy('id')->get()->toJson());
    }

    public function test_production_manual_configuration_cannot_bypass_admin_mfa(): void
    {
        $f = $this->fixture();
        $this->reviewedProduction();
        $this->actingAs($f['maker'])->getJson('/api/commercial/coupons')->assertForbidden()->assertJsonPath('admin_second_factor_required', true);
    }

    public function test_manual_configuration_cannot_enable_point_redemption_or_simulated_accounting(): void
    {
        $f = $this->fixture();
        $service = app(LoyaltyService::class);
        $service->earn($f['invoice']->id, $f['customer']->id);
        $legacy = $service->redeem($f['customer']->id, $f['reward']->id, $f['customer']->id, 'legacy-points');
        $cash = DB::table('finance_journals')->orderBy('id')->get()->toJson();
        $ledger = DB::table('loyalty_ledger')->orderBy('id')->get()->toJson();
        $this->reviewedProduction();
        foreach ([
            fn () => $service->redeem($f['customer']->id, $f['reward']->id, $f['customer']->id, 'production-points'),
            fn () => $service->transition($legacy->id, $f['maker'], 'fulfill', 'Must not activate legacy points'),
            fn () => app(FinanceService::class)->pay($f['invoice'], $f['customer']->id, 1, 'production-simulated-pay'),
            fn () => app(FinanceService::class)->refund($f['invoice'], $f['maker']->id, 1, 'production-simulated-refund', 'Must not activate simulated money'),
        ] as $action) {
            try {
                $action();
                $this->fail('Manual noncash must not activate points or simulated accounting.');
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
        }
        $this->assertSame($cash, DB::table('finance_journals')->orderBy('id')->get()->toJson());
        $this->assertSame($ledger, DB::table('loyalty_ledger')->orderBy('id')->get()->toJson());
    }

    public function test_production_referral_qualification_rejects_simulated_invoice_receipts_even_with_reviewed_programme(): void
    {
        $f = $this->fixture();
        $member = User::factory()->create();
        $this->reviewedProduction();
        $registry = app(ReferralRegistry::class);
        config(['loyalty.referrals.verified_live_source_ready' => true]);
        $p = $registry->publish($f['maker'], ['name' => 'Synthetic reviewed programme', 'name_ar' => 'برنامج مراجعة تجريبي',
            'terms' => 'Explicit consent before the first charging payment. Only a verified live canonical source may qualify production noncash benefits; no money or delivery proof.',
            'terms_ar' => 'موافقة صريحة قبل أول دفعة شحن. يتطلب التأهل مصدر فاتورة حقيقيًا موثقًا؛ لا يوجد تحويل أموال أو إثبات تسليم.',
            'currency' => 'AED', 'minor_units' => 2, 'minimum_minor' => 1,
            'referrer_reward_id' => $f['reward']->id, 'member_reward_id' => $f['reward']->id, 'claim_limit' => 5, 'per_referrer_limit' => 2,
            'starts_at' => now()->subMinute()->toDateTimeString(), 'ends_at' => now()->addHour()->toDateTimeString(), 'idempotency_key' => 'production-programme']);
        $this->assertSame('REVIEWED_MANUAL_NONCASH', $p['evidence']);
        $code = $registry->code($f['customer'], $p['id'], 'production-code')['code'];
        $registry->accept($member, ['code' => $code, 'terms_version' => $p['terms_version'], 'consent' => true, 'idempotency_key' => 'production-consent']);
        // Build only a deliberately simulated source in the isolated test
        // environment, then verify it cannot cross the production boundary.
        $this->app->detectEnvironment(fn () => 'testing');
        $original = DB::table('charging_sessions')->where('id', $f['invoice']->charging_session_id)->first();
        $station = DB::table('reservations')->where('id', $original->reservation_id)->value('station_id');
        $reservation = Reservation::create(['user_id' => $member->id, 'station_id' => $station, 'starts_at' => now(), 'status' => 'cancelled', 'idempotency_key' => 'production-source-fixture']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $finance = app(FinanceService::class);
        $invoice = $finance->issue($session, $member->id);
        $finance->pay($invoice, $member->id, (int) $invoice->total_minor, 'simulated-source');
        $this->reviewedProduction();
        try {
            $registry->qualify($member, $invoice->id, 'must-reject-simulation');
            $this->fail('Simulated receipts cannot qualify production referrals.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('loyalty_referral_qualifications', 0);
        $this->assertDatabaseCount('loyalty_redemptions', 0);
    }

    public function test_manual_coupon_review_does_not_implicitly_activate_paid_referral_source_integration(): void
    {
        $f = $this->fixture();
        $this->reviewedProduction();
        $this->assertTrue(app(NoncashActivation::class)->enabled());
        $this->assertFalse(app(ReferralRegistry::class)->enabled());
        $this->action($f['customer'], 'noncashStatus', [])->assertOk()->assertJsonPath('enabled', true)
            ->assertJsonPath('referrals_enabled', false)->assertJsonPath('points_actions_enabled', false)->assertJsonPath('money_movement', false);
    }

    public function test_review_snapshot_is_database_immutable(): void
    {
        $f = $this->fixture();
        $this->reviewedProduction();
        $this->action($f['maker'], 'publishCoupon', $this->payload($f))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('loyalty_coupon_activation_evidence')->update(['activation_mode' => 'sandbox']);
    }
}
