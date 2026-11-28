<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivacyErasurePreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_independent_current_reviewers_prepare_only_and_preserve_existing_records(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'admin']);
        $case = $this->actingAs($owner)->postJson('/api/account/privacy-requests', [
            'kind' => 'erasure', 'details' => 'Review my personal data retention requirements.',
        ])->assertCreated()->json('id');
        $base = '/api/admin/privacy-requests/'.$case;
        $url = $base.'/erasure-preparation';
        $this->getJson($url)->assertForbidden();
        $this->actingAs($first)->getJson($url)->assertStatus(409);
        $this->patchJson($base.'/review', [
            'expected_status' => 'pending', 'status' => 'reviewing', 'review_note' => 'Start independent review of retained data.',
        ])->assertOk();
        $revision = $this->getJson($url)->assertOk()->assertJsonPath('stage', 'not_prepared')->json('review_revision');
        $proposal = ['action' => 'propose', 'review_revision' => $revision, 'password' => 'password',
            'reason' => 'PRIVATE proposed assisted review note'];
        $this->postJson($url, array_replace($proposal, ['password' => 'invalid']))->assertUnprocessable();
        $plan = $this->postJson($url, $proposal)->assertOk()->assertJsonPath('stage', 'awaiting_independent_review')
            ->assertJsonPath('execution_authorized', false)->json('preparation_id');
        $this->postJson($url, $proposal)->assertOk();
        $this->postJson($url, array_replace($proposal, ['reason' => 'Different private review explanation']))->assertStatus(409);
        $confirmation = ['action' => 'confirm', 'review_revision' => $revision, 'preparation_id' => $plan,
            'password' => 'password', 'reason' => 'PRIVATE independent confirmation note'];
        $this->postJson($url, $confirmation)->assertForbidden();
        $response = $this->actingAs($second)->postJson($url, $confirmation)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('stage', 'assisted_review_prepared')
            ->assertJsonPath('execution_authorized', false)->assertJsonPath('legal_retention_verified', false)
            ->assertJsonPath('automated_erasure_performed', false);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->postJson($url, $confirmation)->assertOk();
        $this->assertDatabaseCount('privacy_erasure_preparations', 1);
        $this->assertDatabaseCount('privacy_erasure_preparation_approvals', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.erasure.preparation_confirmed')->count());
        $this->assertStringNotContainsString('PRIVATE', DB::table('privacy_erasure_preparations')->value('reason_encrypted'));
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'account_status' => 'active']);
        $this->assertDatabaseHas('account_privacy_requests', ['id' => $case, 'status' => 'reviewing']);
        DB::table('users')->where('id', $owner->id)->increment('auth_epoch');
        $this->getJson($url)->assertOk()->assertJsonPath('stage', 'not_prepared');
        $this->postJson($url, $confirmation)->assertStatus(409);
        $this->actingAs($owner->fresh())->postJson('/api/account/privacy-requests/'.$case.'/withdraw')->assertOk();
        $this->actingAs($second)->getJson($url)->assertStatus(409);
        $this->assertDatabaseCount('privacy_erasure_preparation_approvals', 1);
    }
}
