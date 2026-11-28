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

    public function test_cancellation_is_a_durable_payload_bound_fence_even_if_coupon_is_published_later(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $payload = ['code' => $v['code'], 'idempotency_key' => 'unknown-network-intent'];
        $this->actingAs($f['customer']);
        $this->postJson('/api/commercial/coupons/claim-intents/cancel', $payload)->assertOk()->assertJsonPath('state', 'cancelled')->assertJsonPath('claim', null);
        $this->postJson('/api/commercial/coupons/claim-intents/cancel', $payload)->assertOk()->assertJsonPath('state', 'cancelled');
        $this->postJson('/api/commercial/coupons/claim-intents/cancel', array_replace($payload, ['code' => 'CHANGED-COUPON-01']))->assertConflict();
        $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated();
        $this->actingAs($f['customer'])->postJson('/api/commercial/coupons/claim', $payload)->assertConflict();
        $this->assertDatabaseCount('loyalty_coupon_claim_intents', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 0);
    }

    public function test_listed_coupon_cancellation_fences_both_claim_paths_and_preserves_committed_receipt(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $base = '/api/commercial/coupons';
        $listed = $this->actingAs($f['maker'])->postJson($base, $v + ['listed' => true])->assertCreated()->json('id');
        $private = $this->postJson($base, array_replace($v, ['code' => 'PRIVATE-COUPON-99', 'idempotency_key' => 'private-publication']))->assertCreated()->json('id');
        $this->actingAs($f['customer']);
        $payload = ['coupon_id' => $listed, 'idempotency_key' => 'cancel-listed'];
        $request = hash('sha256', 'coupon-claim:'.$f['customer']->id.':cancel-listed');
        $binding = hash('sha256', 'coupon-cancel-v1:'.$listed.':'.$request);
        $this->postJson($base.'/claim-intents/cancel', $payload + ['code' => $v['code']])->assertUnprocessable();
        $this->postJson($base.'/claim-intents/cancel', array_replace($payload, ['coupon_id' => $private]))->assertNotFound();
        config(['loyalty.enabled' => false]);
        $this->postJson($base.'/claim-intents/cancel', $payload)->assertOk()
            ->assertJsonPath('state', 'cancelled')->assertJsonPath('coupon_id', $listed)
            ->assertJsonPath('request_binding', $binding)->assertJsonPath('claim', null)
            ->assertDontSee($v['code'])->assertJsonMissingPath('code_hash');
        $this->postJson($base.'/claim-intents/cancel', $payload)->assertOk()->assertJsonPath('request_binding', $binding);
        config(['loyalty.enabled' => true]);
        $this->postJson($base.'/eligible/'.$listed.'/claim', ['idempotency_key' => 'cancel-listed'])->assertConflict();
        $this->postJson($base.'/claim', ['code' => $v['code'], 'idempotency_key' => 'cancel-listed'])->assertConflict();
        $claim = $this->postJson($base.'/eligible/'.$listed.'/claim', ['idempotency_key' => 'accepted-listed'])->assertOk()->json('claim');
        $this->postJson($base.'/claim-intents/cancel', array_replace($payload, ['idempotency_key' => 'accepted-listed']))
            ->assertOk()->assertJsonPath('state', 'accepted')->assertJsonPath('claim.id', $claim['id']);
        $this->assertDatabaseCount('loyalty_coupon_claim_intents', 1);
        $this->assertDatabaseCount('loyalty_coupon_claims', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_accepted_claim_wins_cancellation_and_returns_original_private_receipt(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated();
        $payload = ['code' => $v['code'], 'idempotency_key' => 'accepted-intent'];
        $id = $this->actingAs($f['customer'])->postJson('/api/commercial/coupons/claim', $payload)->assertOk()->json('claim.id');
        $this->postJson('/api/commercial/coupons/claim-intents/cancel', $payload)->assertOk()->assertJsonPath('state', 'accepted')->assertJsonPath('claim.id', $id);
        $this->assertDatabaseCount('loyalty_coupon_claim_intents', 0);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
    }

    public function test_upgrade_preserves_original_coupon_claims_and_honestly_marks_legacy_revocation(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $id = $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated()->json('id');
        $this->actingAs($f['customer'])->postJson('/api/commercial/coupons/claim', ['code' => $v['code'], 'idempotency_key' => 'legacy-claim'])->assertOk();
        $before = DB::table('loyalty_coupon_claims')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_12_11_000001_harden_loyalty_coupons.php');
        $migration->down();
        DB::table('loyalty_coupons')->where('id', $id)->update(['revoked_at' => now()]);
        $couponBefore = DB::table('loyalty_coupons')->where('id', $id)->first();
        $migration->up();
        $this->assertSame($before, DB::table('loyalty_coupon_claims')->orderBy('id')->get()->toJson());
        $this->assertEquals($couponBefore, DB::table('loyalty_coupons')->where('id', $id)->first());
        $event = DB::table('loyalty_coupon_revocations')->where('coupon_id', $id)->first();
        $this->assertNull($event->actor_id);
        $this->assertTrue((bool) $event->legacy_observation);
        $this->assertStringContainsString('without actor', $event->reason);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_cancelled_intent_evidence_is_immutable_and_cancellation_works_when_economics_are_off(): void
    {
        $f = $this->fixture();
        config(['loyalty.enabled' => false]);
        $payload = ['code' => 'SYNTHETIC-CANCEL-01', 'idempotency_key' => 'cancelled'];
        $this->actingAs($f['customer'])->postJson('/api/commercial/coupons/claim-intents/cancel', $payload)->assertOk()->assertJsonPath('state', 'cancelled');
        $this->expectException(QueryException::class);
        DB::table('loyalty_coupon_claim_intents')->update(['code_hash' => str_repeat('0', 64)]);
    }

    public function test_publication_recovery_is_owner_scoped_and_expiry_does_not_hide_an_existing_receipt(): void
    {
        $f = $this->fixture();
        $v = $this->publication($f);
        $id = $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertCreated()->json('id');
        $this->postJson('/api/commercial/coupons/publication-receipt', ['idempotency_key' => $v['idempotency_key']])->assertOk()->assertJsonPath('coupon.id', $id);
        $this->actingAs($f['checker'])->postJson('/api/commercial/coupons/publication-receipt', ['idempotency_key' => $v['idempotency_key']])->assertOk()->assertJsonPath('coupon', null);
        $this->travel(2)->hours();
        $this->actingAs($f['maker'])->postJson('/api/commercial/coupons', $v)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('loyalty_coupons', 1);
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
