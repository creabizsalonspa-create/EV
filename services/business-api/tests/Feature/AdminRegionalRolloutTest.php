<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminRegionalRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_admin_records_revision_bound_review_without_enabling_rollout(): void
    {
        $url = '/api/admin/regions/AE/rollout';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($url)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('checks.0.decision', 'pending')->assertJsonCount(5, 'checks')
            ->assertJsonPath('external_evidence_verified', false)
            ->assertJsonPath('rollout_authorized', false);

        $values = [
            'domain' => 'legal',
            'decision' => 'reviewed',
            'evidence_reference' => 'LEGAL-AE-001',
            'configuration_revision' => 1,
            'expected_review_id' => null,
        ];
        $created = $this->postJson($url, $values)->assertCreated()
            ->assertJsonPath('checks.0.evidence_reference', 'LEGAL-AE-001')
            ->assertJsonPath('checks.0.decision', 'reviewed');
        $reviewId = $created->json('checks.0.review_id');
        $this->postJson($url, $values)->assertOk()->assertJsonPath('checks.0.review_id', $reviewId);
        $blocked = array_replace($values, ['decision' => 'blocked', 'evidence_reference' => 'LEGAL-AE-002']);
        $this->postJson($url, $blocked)->assertConflict();
        $this->postJson($url, array_replace($blocked, ['expected_review_id' => $reviewId]))->assertCreated()
            ->assertJsonPath('checks.0.decision', 'blocked');
        $this->assertDatabaseCount('regional_rollout_reviews', 2);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'region.rollout_review_recorded')->count());

        DB::table('regions')->where('country', 'AE')->update(['configuration_revision' => 2]);
        $this->postJson($url, $values)->assertConflict();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('configuration_revision', 2)->assertJsonPath('checks.0.decision', 'pending');
        $this->assertDatabaseHas('regions', ['country' => 'AE', 'commerce_enabled' => false]);
        $this->assertDatabaseCount('regional_rollout_reviews', 2);

        DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
        $this->getJson($url)->assertConflict();
    }

    public function test_review_does_not_accept_arbitrary_domains_or_external_links(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/regions/ZZ/rollout')->assertNotFound();
        $this->postJson('/api/admin/regions/AE/rollout', [
            'domain' => 'other',
            'decision' => 'authorized',
            'evidence_reference' => 'https://example.test/private',
            'configuration_revision' => 1,
            'expected_review_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['domain', 'decision', 'evidence_reference']);
        $this->assertDatabaseCount('regional_rollout_reviews', 0);
    }
}
