<?php

namespace Tests\Feature;

use App\Models\User;
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
