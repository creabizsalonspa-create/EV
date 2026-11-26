<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetailPartnerRewardGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTRY = '/api/admin/retail-partners';

    private function evidence(array $extra = []): array
    {
        return ['reason' => 'Reviewed synthetic contractual restriction', 'idempotency_key' => (string) Str::uuid()] + $extra;
    }

    private function setupReview(array $rewardOverrides = []): array
    {
        config(['loyalty.enabled' => true]);
        $maker = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $this->actingAs($maker);
        $partner = $this->postJson(self::REGISTRY, $this->evidence([
            'reference' => 'TEST-'.strtoupper(Str::random(8)), 'legal_name' => 'Synthetic Partner',
            'display_name' => 'Synthetic Partner', 'display_name_ar' => 'شريك تجريبي',
            'country' => 'AE', 'category' => 'retail', 'terms' => 'Internal review of a synthetic noncash reward.',
            'effective_from' => now()->subDay()->format('Y-m-d\TH:i:sP'),
            'expires_at' => now()->addDays(7)->format('Y-m-d\TH:i:sP'),
        ]))->assertCreated()->json('partner.id');
        $reward = $this->postJson('/api/commercial/loyalty/rewards', $rewardOverrides + [
            'name' => 'Synthetic reward', 'name_ar' => 'مكافأة تجريبية',
            'description' => 'Internal review only.', 'description_ar' => 'مراجعة داخلية فقط.',
            'currency' => 'AED', 'points_cost' => 1, 'stock' => 2, 'per_user_limit' => 1,
            'starts_at' => now()->toISOString(), 'ends_at' => now()->addDays(2)->toISOString(),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('reward.id');

        return [$maker, $reviewer, $partner, $reward];
    }

    private function approvePartner(User $reviewer, int $partner): void
    {
        $this->actingAs($reviewer)->postJson(self::REGISTRY."/{$partner}/approve", $this->evidence(['expected_revision' => 1]))->assertOk();
    }

    private function sandboxOffer(array $overrides = []): array
    {
        [$maker, $reviewer, $partner, $reward] = $this->setupReview($overrides + ['starts_at' => now()->subMinute()->toISOString()]);
        $this->approvePartner($reviewer, $partner);
        $path = self::REGISTRY."/{$partner}/reward-reviews";
        $review = $this->actingAs($maker)->postJson($path, $this->evidence(['reward_id' => $reward, 'expected_partner_revision' => 2]))
            ->assertCreated()->json('review.id');
        $this->actingAs($reviewer)->postJson("$path/$review/approve", $this->evidence(['expected_revision' => 1]))->assertOk();
        app(LoyaltyService::class)->publish('loyalty_policies', $maker, [
            'currency' => 'AED', 'minor_units' => 2, 'enabled' => true, 'points_numerator' => 1,
            'minor_denominator' => 10, 'gold_threshold' => 100, 'platinum_threshold' => 500,
            'gold_multiplier_bps' => 12500, 'platinum_multiplier_bps' => 15000, 'invoice_cap' => 1000,
            'daily_cap' => 10000, 'expiry_days' => 30, 'daily_redemption_limit' => 5,
            'effective_from' => now()->subDay()->toDateTimeString(),
        ], 'partner-sandbox-policy');

        return [$reviewer, $review, $path];
    }

    public function test_synthetic_offer_creates_one_private_zero_point_claim_and_uses_canonical_fulfillment(): void
    {
        [$reviewer, $review] = $this->sandboxOffer();
        $customer = User::factory()->create();
        $base = '/api/commercial/partner-rewards';
        $this->getJson("$base/offers")->assertForbidden();
        $this->actingAs($customer)->getJson("$base/offers")->assertOk()->assertJsonPath('data.0.review_id', $review)
            ->assertJsonPath('data.0.evidence', 'SANDBOX_MANUAL_NONCASH')->assertJsonMissingPath('data.0.proposed_by');
        $payload = ['idempotency_key' => 'customer-operation'];
        $claim = $this->postJson("$base/offers/$review/claim", $payload)->assertCreated()
            ->assertJsonPath('claim.status', 'pending')->assertJsonPath('claim.points_cost', 0)
            ->assertJsonPath('claim.external_fulfillment_verified', false)->assertJsonMissingPath('claim.request_key')->json('claim');
        $this->postJson("$base/offers/$review/claim", $payload)->assertCreated()->assertJsonPath('claim.id', $claim['id']);
        $this->postJson("$base/offers/999/claim", $payload)->assertConflict();
        $this->postJson("$base/offers/$review/claim", ['idempotency_key' => 'new-request'])->assertConflict();
        $this->actingAs(User::factory()->create())->getJson("$base/claims")->assertOk()->assertJsonCount(0, 'data');
        $redemption = '/api/commercial/loyalty/redemptions/'.$claim['redemption_id'];
        $this->postJson("$redemption/fulfill", ['reason' => 'Manual synthetic fulfillment'])->assertForbidden();
        $this->actingAs($reviewer)->postJson("$redemption/fulfill", ['reason' => 'Manual synthetic fulfillment'])
            ->assertOk()->assertJsonPath('redemption.status', 'fulfilled');
        $this->actingAs($customer)->getJson("$base/claims")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.status', 'fulfilled')->assertJsonPath('data.0.external_fulfillment_verified', false);
        $this->assertDatabaseCount('partner_reward_claims', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->expectException(QueryException::class);
        DB::table('partner_reward_claims')->where('id', $claim['id'])->delete();
    }

    public function test_cancelled_partner_claim_fences_delayed_dispatch_and_is_idempotent_without_reserving_inventory(): void
    {
        [, $review] = $this->sandboxOffer();
        $customer = User::factory()->create();
        $foreign = User::factory()->create();
        $base = '/api/commercial/partner-rewards';
        $payload = ['review_id' => $review, 'idempotency_key' => 'uncertain-cancel'];
        $key = hash('sha256', 'partner-reward-claim:'.$customer->id.':uncertain-cancel');
        $binding = hash('sha256', 'partner-reward-cancel-v1:'.$review.':'.$key);
        $this->actingAs($customer)->postJson("$base/claim-intents/cancel", $payload)
            ->assertOk()->assertJsonPath('state', 'cancelled')
            ->assertJsonPath('review_id', $review)->assertJsonPath('claim', null)
            ->assertJsonPath('request_binding', $binding)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('request_key');
        $this->postJson("$base/claim-intents/cancel", $payload)
            ->assertOk()->assertJsonPath('request_binding', $binding);
        $this->postJson("$base/claim-intents/cancel", array_replace($payload, ['review_id' => $review + 1]))
            ->assertConflict();
        $this->postJson("$base/offers/$review/claim", ['idempotency_key' => 'uncertain-cancel'])
            ->assertConflict();
        $this->assertDatabaseCount('partner_reward_cancelled_intents', 1);
        $this->assertDatabaseCount('partner_reward_claims', 0);
        $this->assertDatabaseCount('loyalty_redemptions', 0);

        // The same client key is independent for another authenticated member.
        $this->actingAs($foreign)->postJson("$base/offers/$review/claim", ['idempotency_key' => 'uncertain-cancel'])
            ->assertCreated()->assertJsonPath('claim.status', 'pending');
        $this->assertDatabaseCount('partner_reward_claims', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 0);

        $this->expectException(QueryException::class);
        DB::table('partner_reward_cancelled_intents')->where('request_key', $key)->delete();
    }

    public function test_cancellation_returns_already_accepted_claim_even_after_activation_disabled(): void
    {
        [$reviewer, $review] = $this->sandboxOffer();
        $customer = User::factory()->create();
        $base = '/api/commercial/partner-rewards';
        $payload = ['review_id' => $review, 'idempotency_key' => 'accepted-first'];
        $claim = $this->actingAs($customer)->postJson("$base/offers/$review/claim", ['idempotency_key' => $payload['idempotency_key']])
            ->assertCreated()->json('claim');

        config(['loyalty.enabled' => false]);
        $this->postJson("$base/claim-intents/cancel", $payload)->assertOk()
            ->assertJsonPath('state', 'accepted')->assertJsonPath('claim.id', $claim['id'])
            ->assertJsonPath('claim.status', 'pending')->assertJsonMissingPath('claim.request_key')
            ->assertJsonPath('claim.external_fulfillment_verified', false);
        $this->postJson("$base/claim-intents/cancel", array_replace($payload, ['review_id' => $review + 1]))
            ->assertConflict();
        $this->actingAs($reviewer)->postJson("$base/claim-intents/cancel", $payload)->assertForbidden();
        $this->assertDatabaseCount('partner_reward_cancelled_intents', 0);
        $this->assertDatabaseCount('partner_reward_claims', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_offer_discovery_filters_member_and_shared_inventory_before_pagination(): void
    {
        [$reviewer, $review] = $this->sandboxOffer(['stock' => 2, 'per_user_limit' => 1]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $third = User::factory()->create();
        $base = '/api/commercial/partner-rewards';
        $this->actingAs($first)->getJson("$base/offers?page=0")->assertUnprocessable();
        $this->getJson("$base/offers?page=100001")->assertUnprocessable();
        $this->getJson("$base/offers?page=1")->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.review_id', $review);
        $this->getJson("$base/offers?page=2")->assertOk()->assertJsonPath('current_page', 2)
            ->assertJsonPath('total', 1)->assertJsonCount(0, 'data');
        $claim = $this->postJson("$base/offers/$review/claim", ['idempotency_key' => 'first-claim'])
            ->assertCreated()->json('claim');
        $this->getJson("$base/offers")->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->actingAs($second)->getJson("$base/offers")->assertOk()->assertJsonPath('total', 1);
        $this->postJson("$base/offers/$review/claim", ['idempotency_key' => 'second-claim'])->assertCreated();
        $this->actingAs($third)->getJson("$base/offers")->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($reviewer)->postJson('/api/commercial/loyalty/redemptions/'.$claim['redemption_id'].'/reject',
            ['reason' => 'Release synthetic reward inventory'])->assertOk();
        $this->actingAs($third)->getJson("$base/offers")->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($first)->getJson("$base/offers")->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($second)->getJson("$base/offers")->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('partner_reward_claims', 2);
        $this->assertDatabaseCount('loyalty_redemptions', 2);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_revoked_review_blocks_new_claims_and_fulfillment_but_preserves_receipt_and_release(): void
    {
        [$reviewer, $review, $path] = $this->sandboxOffer();
        $customer = User::factory()->create();
        $base = '/api/commercial/partner-rewards';
        $payload = ['idempotency_key' => 'pre-revocation'];
        $claim = $this->actingAs($customer)->postJson("$base/offers/$review/claim", $payload)->assertCreated()->json('claim');
        $this->actingAs($reviewer)->postJson("$path/$review/revoke", $this->evidence(['expected_revision' => 2]))->assertOk();
        $redemption = '/api/commercial/loyalty/redemptions/'.$claim['redemption_id'];
        $this->postJson("$redemption/fulfill", ['reason' => 'Stale review must not fulfill'])->assertConflict();
        $this->postJson("$redemption/reject", ['reason' => 'Reviewed offer revoked'])->assertOk()->assertJsonPath('redemption.status', 'rejected');
        $this->actingAs($customer)->getJson("$base/offers")->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("$base/offers/$review/claim", $payload)->assertCreated()->assertJsonPath('claim.status', 'rejected');
        $this->postJson("$base/offers/$review/claim", ['idempotency_key' => 'post-revocation'])->assertConflict();
        config(['loyalty.enabled' => false]);
        $this->getJson("$base/offers")->assertStatus(503);
        $this->getJson("$base/claims")->assertOk()->assertJsonPath('data.0.status', 'rejected');
        $this->postJson("$base/claim-receipt", $payload)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('claim.status', 'rejected');
        $this->actingAs(User::factory()->create())->postJson("$base/claim-receipt", $payload)
            ->assertOk()->assertJsonPath('claim', null);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_native_bearer_claim_requires_current_verified_customer_and_stays_synthetic(): void
    {
        [, $review] = $this->sandboxOffer();
        $base = '/api/v1/commercial/partner-rewards';
        $unverified = User::factory()->unverified()->create();
        $customer = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($unverified->createToken('mobile')->plainTextToken)
            ->getJson("$base/offers")->assertForbidden();
        $this->postJson("$base/offers/$review/claim", ['idempotency_key' => 'unverified'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($customer->createToken('mobile')->plainTextToken)
            ->postJson("$base/offers/$review/claim", ['idempotency_key' => 'native'])->assertCreated();

        config(['loyalty.noncash.mode' => 'reviewed_manual', 'loyalty.noncash.fulfillment_reviewed' => true,
            'loyalty.noncash.approval_reference' => 'QA-DO-NOT-ACTIVATE', 'loyalty.noncash.terms_sha256' => str_repeat('a', 64)]);
        $this->app->instance('env', 'production');
        try {
            $this->getJson("$base/offers")->assertStatus(503);
            $this->postJson("$base/offers/$review/claim", ['idempotency_key' => 'production'])->assertStatus(503);
            $this->getJson("$base/claims")->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.evidence', 'SANDBOX_MANUAL_NONCASH');
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_review_requires_exact_partner_terms_and_independent_admin_without_fulfilling_rewards(): void
    {
        [$maker, $reviewer, $partner, $reward] = $this->setupReview();
        $path = self::REGISTRY."/{$partner}/reward-reviews";
        $proposal = $this->evidence(['reward_id' => $reward, 'expected_partner_revision' => 2]);
        $this->postJson($path, $proposal)->assertConflict();
        $this->approvePartner($reviewer, $partner);
        $this->actingAs($maker);
        $review = $this->postJson($path, $proposal)->assertCreated()->assertJsonPath('review.decision', 'propose')
            ->assertJsonPath('review.partner_revision', 2)->assertJsonPath('review.fulfillment_enabled', false)->json('review.id');
        $this->postJson($path, $proposal)->assertCreated()->assertJsonPath('review.id', $review);
        $this->postJson($path, $this->evidence(['reward_id' => $reward, 'expected_partner_revision' => 2]))->assertConflict();
        $decision = $this->evidence(['expected_revision' => 1]);
        $this->postJson("$path/$review/approve", $decision)->assertConflict();
        $this->actingAs($reviewer)->postJson("$path/$review/approve", $decision)->assertOk()
            ->assertJsonPath('review.terms_current', true)->assertJsonPath('review.fulfillment_enabled', false);
        $this->postJson("$path/$review/reject", $decision)->assertConflict();
        $this->postJson("$path/$review/approve", $decision)->assertOk()->assertJsonPath('review.revision', 2);
        $this->postJson("$path/$review/revoke", $this->evidence(['expected_revision' => 2]))->assertOk();
        $this->getJson("$path/$review")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('review.decision', 'revoke')->assertJsonCount(3, 'events.data')->assertJsonMissingPath('events.data.0.request_key');
        $this->assertDatabaseCount('loyalty_redemptions', 0);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertSame(3, DB::table('audit_events')->where('action', 'like', 'loyalty.partner_reward.%')->count());
    }

    public function test_suspension_and_reinstatement_require_a_new_review_and_records_are_private(): void
    {
        [$maker, $reviewer, $partner, $reward] = $this->setupReview();
        $this->approvePartner($reviewer, $partner);
        $path = self::REGISTRY."/{$partner}/reward-reviews";
        $review = $this->actingAs($maker)->postJson($path, $this->evidence(['reward_id' => $reward, 'expected_partner_revision' => 2]))
            ->assertCreated()->json('review.id');
        $this->actingAs($reviewer)->postJson(self::REGISTRY."/{$partner}/suspend", $this->evidence(['expected_revision' => 2]))->assertOk();
        $this->postJson("$path/$review/approve", $this->evidence(['expected_revision' => 1]))->assertConflict();
        $this->postJson(self::REGISTRY."/{$partner}/reinstate", $this->evidence(['expected_revision' => 3]))->assertOk();
        $this->getJson("$path/$review")->assertOk()->assertJsonPath('review.terms_current', false);
        $this->postJson("$path/$review/approve", $this->evidence(['expected_revision' => 1]))->assertConflict();
        $this->postJson("$path/$review/reject", $this->evidence(['expected_revision' => 1]))->assertOk();
        $this->actingAs($maker)->postJson($path, $this->evidence(['reward_id' => $reward, 'expected_partner_revision' => 4]))->assertCreated();

        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson("$path/$review")->assertForbidden();
        }
    }

    public function test_wrong_currency_or_out_of_term_reward_is_ineligible(): void
    {
        [$maker, $reviewer, $partner, $reward] = $this->setupReview(['currency' => 'USD', 'ends_at' => now()->addDays(8)->toISOString()]);
        $this->approvePartner($reviewer, $partner);
        $this->actingAs($maker)->postJson(self::REGISTRY."/{$partner}/reward-reviews", $this->evidence(['reward_id' => $reward, 'expected_partner_revision' => 2]))
            ->assertConflict();
        $this->assertDatabaseCount('partner_reward_reviews', 0);
    }

    public function test_governance_history_is_immutable(): void
    {
        [$maker, $reviewer, $partner, $reward] = $this->setupReview();
        $this->approvePartner($reviewer, $partner);
        $review = $this->actingAs($maker)->postJson(self::REGISTRY."/{$partner}/reward-reviews", $this->evidence(['reward_id' => $reward, 'expected_partner_revision' => 2]))
            ->assertCreated()->json('review.id');
        $this->expectException(QueryException::class);
        DB::table('partner_reward_review_events')->where('review_id', $review)->update(['reason' => 'Altered evidence']);
    }
}
