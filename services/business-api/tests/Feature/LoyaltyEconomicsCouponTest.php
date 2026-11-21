<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LoyaltyEconomicsCouponTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['loyalty.enabled' => true]);
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        $s = app(LoyaltyService::class);
        $s->publish('loyalty_policies', $f['maker'], ['currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1, 'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500, 'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000, 'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5, 'effective_from' => now()->subDay()->toDateTimeString()], 'coupon-policy');
        $f['reward'] = $s->publish('loyalty_rewards', $f['maker'], ['currency' => 'AED', 'name' => 'Synthetic bottle', 'name_ar' => 'زجاجة اختبار', 'description' => 'Manual noncash benefit', 'description_ar' => 'مكافأة اختبار غير نقدية', 'points_cost' => 80, 'stock' => 2, 'per_user_limit' => 1, 'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString()], 'coupon-reward');

        return $f;
    }

    private function publication(array $f): array
    {
        return ['code' => 'SYNTHETIC-COUPON-01', 'reward_id' => $f['reward']->id, 'claim_limit' => 2, 'per_user_limit' => 1, 'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(), 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_claim_receipt_is_request_bound_and_recoverable_without_plaintext_history(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated();
        $key = (string) Str::uuid();
        $binding = hash('sha256', hash('sha256', $v['code']).':'.hash('sha256', 'coupon-claim:'.$f['customer']->id.':'.$key));
        $claim = $this->actingAs($f['customer'])->postJson('/api/commercial/coupons/claim', ['code' => $v['code'], 'idempotency_key' => $key])->assertOk()
            ->assertJsonPath('claim.request_binding', $binding)->assertJsonPath('claim.source', 'COUPON')->assertJsonPath('claim.delivery_verified', false)->json('claim.id');
        $this->postJson('/api/commercial/coupons/claim-receipt', ['idempotency_key' => $key])->assertOk()->assertJsonPath('claim.id', $claim)->assertJsonPath('claim.request_binding', $binding);
        $this->getJson('/api/commercial/coupons/claims')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($v['code'])->assertJsonMissingPath('data.0.request_key');
        $this->actingAs(User::factory()->create())->postJson('/api/commercial/coupons/claim-receipt', ['idempotency_key' => $key])->assertOk()->assertJsonPath('claim', null);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_revocation_is_reason_bound_terminal_and_preserves_existing_claims(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $id = $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated()->json('id');
        $this->actingAs($f['customer'])->postJson('/api/commercial/coupons/claim', ['code' => $v['code'], 'idempotency_key' => 'existing'])->assertOk();
        $this->actingAs($f['maker']);
        $this->postJson("/api/commercial/coupons/{$id}/revoke")->assertUnprocessable();
        $this->postJson("/api/commercial/coupons/{$id}/revoke", ['reason' => 'Synthetic decision to stop new claims'])->assertOk()->assertJsonPath('revocation.reason', 'Synthetic decision to stop new claims');
        $this->postJson("/api/commercial/coupons/{$id}/revoke", ['reason' => 'Synthetic decision to stop new claims'])->assertOk();
        $this->postJson("/api/commercial/coupons/{$id}/revoke", ['reason' => 'Changed reason'])->assertConflict();
        $this->actingAs(User::factory()->create())->postJson('/api/commercial/coupons/claim', ['code' => $v['code'], 'idempotency_key' => 'new'])->assertConflict();
        $this->assertDatabaseCount('loyalty_coupon_revocations', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseHas('loyalty_redemptions', ['status' => 'pending']);
    }

    #[TestWith(['loyalty_coupons', 'claim_limit', 99])]
    #[TestWith(['loyalty_coupon_claims', 'request_key', 'tampered'])]
    #[TestWith(['loyalty_coupon_revocations', 'reason', 'tampered'])]
    public function test_database_preserves_coupon_commitments(string $table, string $field, mixed $value): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $id = $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated()->json('id');
        $this->actingAs($f['customer'])->postJson('/api/commercial/coupons/claim', ['code' => $v['code'], 'idempotency_key' => 'claim'])->assertOk();
        $this->actingAs($f['maker'])->postJson("/api/commercial/coupons/{$id}/revoke", ['reason' => 'Synthetic stop claims'])->assertOk();
        $this->expectException(QueryException::class);
        DB::table($table)->update([$field => $value]);
    }

    public function test_held_expired_and_disabled_economics_do_not_create_claims_or_cash(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated();
        $this->actingAs($f['customer']);
        config(['loyalty.enabled' => false]);
        $this->postJson('/api/commercial/coupons/claim', ['code' => $v['code'], 'idempotency_key' => 'off'])->assertStatus(503);
        config(['loyalty.enabled' => true]);
        $this->travel(2)->hours();
        $this->postJson('/api/commercial/coupons/claim', ['code' => $v['code'], 'idempotency_key' => 'expired'])->assertConflict();
        $this->assertDatabaseCount('loyalty_coupon_claims', 0);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }
}
