<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyEconomicsReferralTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $limits = []): array
    {
        config(['loyalty.enabled' => true]);
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        $f['member'] = User::factory()->create();
        $service = app(LoyaltyService::class);
        $service->publish('loyalty_policies', $f['maker'], ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'referral-policy');
        $rewards = [];
        foreach (['referrer', 'member'] as $role) {
            $rewards[$role] = $service->publish('loyalty_rewards', $f['maker'], ['currency' => 'AED', 'name' => 'Synthetic '.$role.' benefit', 'name_ar' => 'مكافأة تجريبية', 'description' => 'Manual noncash benefit', 'description_ar' => 'مكافأة يدوية تجريبية', 'points_cost' => 80, 'stock' => 10, 'per_user_limit' => 10, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()], 'referral-reward-'.$role);
        }
        $v = array_replace(['name' => 'Synthetic member referral', 'name_ar' => 'إحالة عضو تجريبية',
            'terms' => 'Explicit opt-in before the first charging payment. A qualifying fully captured post-consent first invoice reserves two manual noncash benefits. No money, points, discount or delivery proof is promised.',
            'terms_ar' => 'تتطلب الإحالة موافقة صريحة قبل أول دفعة شحن. تمنح الفاتورة المؤهلة مكافأتين يدويتين غير نقديتين. لا يوجد تحويل أموال أو خصم أو إثبات تسليم.',
            'currency' => 'AED', 'minor_units' => 2, 'minimum_minor' => 100, 'referrer_reward_id' => $rewards['referrer']->id,
            'member_reward_id' => $rewards['member']->id, 'claim_limit' => 10, 'per_referrer_limit' => 5,
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => (string) Str::uuid()], $limits);
        $f['program'] = $this->actingAs($f['maker'])->postJson('/api/commercial/referral-programs', $v)->assertCreated()->json('program');
        $f['code'] = $this->actingAs($f['customer'])->postJson('/api/commercial/referrals/codes', ['program_id' => $f['program']['id'], 'idempotency_key' => 'own-code'])->assertCreated()->json('code');
        $f['terms'] = $v;

        return $f;
    }

    private function accept(array $f, ?User $member = null, string $key = 'accept-one')
    {
        return $this->actingAs($member ?? $f['member'])->postJson('/api/commercial/referrals/accept', ['code' => $f['code'], 'terms_version' => $f['program']['terms_version'], 'consent' => true, 'idempotency_key' => $key]);
    }

    private function invoice(array $f, ?User $member = null): object
    {
        $member ??= $f['member'];
        $this->travel(2)->seconds();
        $original = DB::table('charging_sessions')->where('id', $f['invoice']->charging_session_id)->first();
        $station = DB::table('reservations')->where('id', $original->reservation_id)->value('station_id');
        $reservation = Reservation::create(['user_id' => $member->id, 'station_id' => $station, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => (string) Str::uuid()]);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        $service = app(FinanceService::class);
        $invoice = $service->issue($session, $member->id);
        $service->pay($invoice, $member->id, (int) $invoice->total_minor, (string) Str::uuid());

        return $invoice;
    }

    public function test_complete_verified_referral_uses_only_canonical_noncash_redemptions_and_private_receipts(): void
    {
        $f = $this->fixture();
        $binding = hash('sha256', hash('sha256', $f['code']).':'.$f['program']['terms_version'].':'.hash('sha256', 'referral-accept:'.$f['member']->id.':accept-one'));
        $id = $this->accept($f)->assertCreated()->assertJsonPath('referral.request_binding', $binding)->assertJsonPath('referral.status', 'accepted')->json('referral.id');
        $this->accept($f)->assertCreated()->assertJsonPath('referral.id', $id);
        $invoice = $this->invoice($f);
        $cash = DB::table('finance_journals')->orderBy('id')->get()->toJson();
        $receipt = $this->actingAs($f['member'])->postJson('/api/commercial/referrals/qualify', ['invoice_id' => $invoice->id, 'idempotency_key' => 'qualify'])->assertOk()->assertJsonPath('referral.status', 'qualified')->json('referral');
        $this->assertSame(hash('sha256', 'referral-qualify:'.$f['member']->id.':'.$id.':'.$invoice->id.':qualify'), $receipt['qualification']['request_binding']);
        $this->postJson('/api/commercial/referrals/qualify', ['invoice_id' => $invoice->id, 'idempotency_key' => 'qualify'])->assertOk();
        $this->assertDatabaseCount('loyalty_redemptions', 2);
        $this->assertDatabaseCount('loyalty_referral_qualifications', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertSame($cash, DB::table('finance_journals')->orderBy('id')->get()->toJson());
        $this->getJson('/api/commercial/referrals/account?program_id='.$f['program']['id'])->assertOk()->assertJsonPath('referral.status', 'qualified')->assertJsonMissingPath('referral.referrer_id');
        $this->actingAs($f['customer'])->getJson('/api/commercial/referrals/rewards')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'referrer')->assertJsonPath('data.0.source_currently_eligible', true)
            ->assertJsonMissingPath('data.0.invoice_id')->assertDontSee($f['member']->email)->assertJsonPath('data.0.points_cost', 0);
        $this->getJson('/api/commercial/referrals/account?program_id='.$f['program']['id'])->assertOk()->assertJsonPath('qualified_count', 1)->assertJsonPath('referral', null);
        $this->assertSame($f['member']->id, (int) DB::table('loyalty_redemption_events')->orderBy('id')->value('actor_id'));
    }

    public function test_existing_payer_self_referral_wrong_terms_and_noncustomer_roles_are_rejected(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer'])->postJson('/api/commercial/referrals/accept', ['code' => $f['code'], 'terms_version' => $f['program']['terms_version'], 'consent' => true, 'idempotency_key' => 'self'])->assertConflict();
        $this->actingAs($f['member'])->postJson('/api/commercial/referrals/accept', ['code' => $f['code'], 'terms_version' => str_repeat('0', 64), 'consent' => true, 'idempotency_key' => 'wrong'])->assertConflict();
        $this->actingAs($f['vendor'])->getJson('/api/commercial/referral-programs')->assertForbidden();
        $this->actingAs($f['vendor'])->postJson('/api/commercial/referrals/codes', ['program_id' => $f['program']['id'], 'idempotency_key' => 'vendor'])->assertForbidden();
        $this->invoice($f);
        $this->accept($f)->assertConflict();
        $this->assertDatabaseCount('loyalty_referrals', 0);
    }

    public function test_withdrawal_is_durable_reason_bound_and_prevents_late_qualification(): void
    {
        $f = $this->fixture();
        $this->accept($f)->assertCreated();
        $this->postJson('/api/commercial/referrals/withdraw', ['reason' => 'Withdraw unqualified consent'])->assertOk()->assertJsonPath('referral.status', 'withdrawn');
        $this->postJson('/api/commercial/referrals/withdraw', ['reason' => 'Withdraw unqualified consent'])->assertOk();
        $this->postJson('/api/commercial/referrals/withdraw', ['reason' => 'Different reason'])->assertConflict();
        $invoice = $this->invoice($f);
        $this->postJson('/api/commercial/referrals/qualify', ['invoice_id' => $invoice->id, 'idempotency_key' => 'late'])->assertConflict();
        $this->assertDatabaseCount('loyalty_redemptions', 0);
        $this->accept($f, null, 'replacement')->assertConflict();
    }

    public function test_source_refunds_hold_fulfillment_without_altering_the_original_financial_ledger(): void
    {
        $f = $this->fixture();
        $this->accept($f)->assertCreated();
        $invoice = $this->invoice($f);
        $this->actingAs($f['member'])->postJson('/api/commercial/referrals/qualify', ['invoice_id' => $invoice->id, 'idempotency_key' => 'qualify'])->assertOk();
        $source = DB::table('loyalty_referral_rewards')->where('role', 'referrer')->first();
        app(FinanceService::class)->refund($invoice, $f['maker']->id, (int) $invoice->total_minor, 'referral-refund', 'Synthetic canonical refund');
        $cash = DB::table('finance_journals')->orderBy('id')->get()->toJson();
        $this->actingAs($f['maker'])->postJson('/api/commercial/loyalty/redemptions/'.$source->redemption_id.'/fulfill', ['reason' => 'Must not deliver after refund'])->assertConflict();
        $this->actingAs($f['customer'])->getJson('/api/commercial/referrals/rewards')->assertOk()->assertJsonPath('data.0.source_currently_eligible', false);
        $this->actingAs($f['maker'])->postJson('/api/commercial/loyalty/redemptions/'.$source->redemption_id.'/reject', ['reason' => 'Source refund prevents delivery'])->assertOk();
        $this->assertSame($cash, DB::table('finance_journals')->orderBy('id')->get()->toJson());
    }

    public function test_expiry_revocation_and_current_account_status_never_grant_half_a_reward_pair(): void
    {
        $f = $this->fixture();
        $this->accept($f)->assertCreated();
        $invoice = $this->invoice($f);
        DB::table('users')->where('id', $f['customer']->id)->update(['account_status' => 'suspended']);
        $this->actingAs($f['member'])->postJson('/api/commercial/referrals/qualify', ['invoice_id' => $invoice->id, 'idempotency_key' => 'qualify'])->assertForbidden();
        DB::table('users')->where('id', $f['customer']->id)->update(['account_status' => 'active']);
        $this->actingAs($f['maker'])->postJson('/api/commercial/referral-programs/'.$f['program']['id'].'/revoke', ['reason' => 'Stop synthetic programme'])->assertOk()->assertJsonPath('program.active', false);
        $this->actingAs($f['member'])->postJson('/api/commercial/referrals/qualify', ['invoice_id' => $invoice->id, 'idempotency_key' => 'qualify'])->assertConflict();
        $this->assertDatabaseCount('loyalty_referral_qualifications', 0);
        $this->assertDatabaseCount('loyalty_redemptions', 0);
    }

    public function test_temporary_economics_disable_does_not_rewrite_recorded_consent_and_still_allows_withdrawal(): void
    {
        $f = $this->fixture();
        $this->accept($f)->assertCreated();
        config(['loyalty.enabled' => false]);
        $this->getJson('/api/commercial/referrals/account?program_id='.$f['program']['id'])->assertOk()->assertJsonPath('referral.status', 'accepted')->assertJsonPath('software_activation', false);
        $this->postJson('/api/commercial/referrals/withdraw', ['reason' => 'Withdraw during service pause'])->assertOk()->assertJsonPath('referral.status', 'withdrawn');
        $this->assertDatabaseCount('loyalty_redemptions', 0);
    }
}
