<?php

namespace Tests\Feature;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPrivacyAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner): AccountPrivacyRequest
    {
        return AccountPrivacyRequest::create([
            'user_id' => $owner->id,
            'kind' => 'correction',
            'status' => 'pending',
            'request_details_encrypted' => Crypt::encryptString(
                'Private owner correction request REFERENCE-SECRET-900.'
            ),
        ]);
    }

    private function decision(): array
    {
        return [
            'expected_status' => 'pending',
            'status' => 'reviewing',
            'review_note' => 'Manual privacy case review; no external erasure claimed.',
        ];
    }

    public function test_demoted_admin_cannot_read_privacy_queue_or_reviewer_notes(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $case = $this->caseFor($owner);
        DB::table('account_privacy_request_reviews')->insert([
            'privacy_request_id' => $case->id,
            'reviewer_id' => $admin->id,
            'from_status' => 'pending',
            'to_status' => 'reviewing',
            'note_encrypted' => Crypt::encryptString('CONFIDENTIAL REVIEWER NOTE'),
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'web');
        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);

        $queue = $this->getJson('/api/admin/privacy-requests')->assertForbidden();
        $history = $this->getJson('/api/admin/privacy-requests/'.$case->id.'/reviews')
            ->assertForbidden();
        foreach ([$queue, $history] as $response) {
            $this->assertStringNotContainsString('CONFIDENTIAL REVIEWER NOTE', $response->getContent());
            $this->assertStringNotContainsString('REFERENCE-SECRET-900', $response->getContent());
        }

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin', 'auth_epoch' => 1,
        ]);
        $this->getJson('/api/admin/privacy-requests')->assertStatus(409);
        $this->getJson('/api/admin/privacy-requests/'.$case->id.'/reviews')
            ->assertStatus(409);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoked_reviewer_cannot_mutate_private_case_or_write_audit(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $case = $this->caseFor($owner);
        $path = '/api/admin/privacy-requests/'.$case->id.'/review';
        $this->actingAs($admin, 'web');

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'suspended',
        ]);
        $this->patchJson($path, $this->decision())->assertForbidden();
        $this->assertSame('pending', $case->fresh()->status);

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active', 'auth_epoch' => 1,
        ]);
        $this->patchJson($path, $this->decision())->assertStatus(409);
        $this->assertSame('pending', $case->fresh()->status);
        $this->assertDatabaseCount('account_privacy_request_reviews', 0);
        $this->assertDatabaseCount('audit_events', 0);

        DB::table('users')->where('id', $admin->id)->update(['auth_epoch' => 0]);
        $this->patchJson($path, $this->decision())->assertOk()
            ->assertJsonPath('status', 'reviewing')
            ->assertJsonPath('automated_erasure_performed', false);
        $this->assertSame('reviewing', $case->fresh()->status);
        $this->assertDatabaseCount('account_privacy_request_reviews', 1);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'privacy.review.reviewing',
            'subject' => (string) $case->id,
        ]);
    }

    public function test_authorized_privacy_queue_and_history_reads_remain_private_and_audited(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $case = $this->caseFor($owner);
        $this->actingAs($admin, 'web');

        $queue = $this->getJson('/api/admin/privacy-requests?user_id='.$owner->id)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.details', 'Private owner correction request REFERENCE-SECRET-900.')
            ->assertHeader('Cache-Control', 'no-store, private');
        $queue->assertJsonMissingPath('data.0.request_details_encrypted')
            ->assertJsonMissingPath('data.0.review_note_encrypted');
        $this->getJson('/api/admin/privacy-requests/'.$case->id.'/reviews')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.privacy_viewed',
            'subject' => 'user:'.$owner->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_history.privacy_viewed',
            'subject' => (string) $case->id,
        ]);
        $this->assertDatabaseCount('audit_events', 2);
        $this->assertStringNotContainsString('REFERENCE-SECRET-900', DB::table('audit_events')->get()->toJson());
    }
}
