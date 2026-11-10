<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountPrivacyRequestTest extends TestCase
{
    use RefreshDatabase;

    private function submit(string $kind = 'erasure', string $details = 'Please review my account privacy and retained records.'): array
    {
        return ['kind' => $kind, 'details' => $details];
    }

    public function test_self_service_requires_authentication_and_never_discloses_other_requests(): void
    {
        $this->getJson('/api/account/privacy-requests')->assertUnauthorized();
        $this->postJson('/api/account/privacy-requests', $this->submit())->assertUnauthorized();

        $alice = User::factory()->create(['role' => 'customer']);
        $bob = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($alice)->postJson('/api/account/privacy-requests',
            $this->submit())->assertCreated()->assertJsonPath('status', 'pending');

        $this->actingAs($bob)->getJson('/api/account/privacy-requests')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($alice)->getJson('/api/account/privacy-requests')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.kind', 'erasure')
            ->assertJsonMissingPath('data.0.request_details_encrypted')
            ->assertJsonMissingPath('data.0.review_note_encrypted');
    }

    public function test_request_is_encrypted_idempotently_bounded_and_not_an_erasure_claim(): void
    {
        $user = User::factory()->create();
        $private = 'Please review account with private reference SECRETS-ABC-900.';
        $response = $this->actingAs($user)
            ->postJson('/api/account/privacy-requests', $this->submit('erasure', $private))
            ->assertCreated()->assertJsonPath('status', 'pending')
            ->assertJsonPath('manual_processing_required', true)
            ->assertJsonPath('automated_erasure_performed', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $row = DB::table('account_privacy_requests')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('SECRETS-ABC-900', $row->request_details_encrypted);
        $this->assertStringNotContainsString('SECRETS-ABC-900', $response->getContent());
        $this->postJson('/api/account/privacy-requests', $this->submit('erasure', $private))
            ->assertStatus(409);
        $this->assertDatabaseCount('account_privacy_requests', 1);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $user->id, 'action' => 'privacy.requested.erasure',
            'subject' => (string) $row->id,
        ]);
    }

    public function test_customer_and_vendor_can_submit_from_native_api_with_personal_tokens(): void
    {
        foreach (['customer', 'vendor'] as $role) {
            $account = User::factory()->create(['role' => $role]);
            $token = $account->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->postJson('/api/v1/privacy-requests',
                $this->submit('access', 'Please tell me what private account information is held.'))
                ->assertCreated()->assertJsonPath('kind', 'access');
            $this->withToken($token)->getJson('/api/v1/privacy-requests')
                ->assertOk()->assertJsonPath('total', 1);
        }
    }

    public function test_admin_can_filter_and_review_without_exposing_internal_notes_to_customer(): void
    {
        $alice = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($alice)->postJson('/api/account/privacy-requests',
            $this->submit('correction', 'My legal contact details are outdated in the platform.'))
            ->assertCreated();
        $caseId = DB::table('account_privacy_requests')->value('id');

        $this->getJson('/api/admin/privacy-requests')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/privacy-requests?kind=correction&status=pending')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.owner.email', $alice->email)
            ->assertJsonPath('data.0.details', 'My legal contact details are outdated in the platform.')
            ->assertJsonPath('data.0.automated_erasure_performed', false)
            ->assertJsonMissingPath('data.0.request_details_encrypted');

        $this->patchJson('/api/admin/privacy-requests/'.$caseId.'/review', [
            'expected_status' => 'pending',
            'status' => 'reviewing',
            'review_note' => 'Verified request origin, awaiting records review.',
        ])->assertOk()->assertJsonPath('status', 'reviewing');
        $row = DB::table('account_privacy_requests')->first();
        $this->assertStringNotContainsString('Verified request origin', $row->review_note_encrypted);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id, 'action' => 'privacy.review.reviewing',
            'subject' => (string) $caseId,
        ]);
        $this->actingAs($alice)->getJson('/api/account/privacy-requests')
            ->assertOk()->assertJsonPath('data.0.status', 'reviewing')
            ->assertJsonMissingPath('data.0.review_note_encrypted')
            ->assertDontSee('Verified request origin');
    }

    public function test_opening_private_admin_request_queue_is_audited_without_plaintext_details(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $reason = 'Sensitive user request reference SECRET-CUSTOMER-872.';
        $this->actingAs($customer)->postJson(
            '/api/account/privacy-requests', $this->submit('access', $reason)
        )->assertCreated();
        $this->getJson('/api/admin/privacy-requests')->assertForbidden();
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'admin.private_list.privacy_viewed')->count());

        $this->actingAs($admin)->getJson(
            '/api/admin/privacy-requests?user_id='.$customer->id
        )->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.details', $reason);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.privacy_viewed',
            'subject' => 'user:'.$customer->id,
        ]);
        $events = DB::table('audit_events')
            ->where('action', 'admin.private_list.privacy_viewed')->get();
        $this->assertCount(1, $events);
        $this->assertStringNotContainsString($reason, $events->toJson());

        $this->getJson('/api/admin/privacy-requests?status=malformed')
            ->assertUnprocessable();
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'admin.private_list.privacy_viewed')->count());
    }

    public function test_admin_review_transitions_are_version_bound_and_do_not_auto_erase(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->postJson('/api/account/privacy-requests', $this->submit())
            ->assertCreated();
        $id = DB::table('account_privacy_requests')->value('id');
        $path = '/api/admin/privacy-requests/'.$id.'/review';
        $this->actingAs($admin)->patchJson($path, [
            'expected_status' => 'reviewing', 'status' => 'blocked_external',
            'review_note' => 'External retention requirements still under evaluation.',
        ])->assertStatus(409);

        $this->patchJson($path, [
            'expected_status' => 'pending', 'status' => 'reviewing',
            'review_note' => 'Manual review started with data owner.',
        ])->assertOk();
        $this->patchJson($path, [
            'expected_status' => 'reviewing', 'status' => 'closed_review',
            'review_note' => 'Documented manual disposition for external processing.',
        ])->assertOk()->assertJsonPath('automated_erasure_performed', false);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('account_privacy_requests', [
            'id' => $id, 'status' => 'closed_review',
        ]);
        $this->patchJson($path, [
            'expected_status' => 'closed_review', 'status' => 'reviewing',
            'review_note' => 'Attempting to reopen a closed review.',
        ])->assertStatus(409);
        $this->assertDatabaseCount('account_privacy_requests', 1);
    }

    public function test_review_history_is_append_only_encrypted_and_only_visible_to_admin(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($customer)->postJson(
            '/api/account/privacy-requests', $this->submit()
        )->assertCreated();
        $id = DB::table('account_privacy_requests')->value('id');
        $history = "/api/admin/privacy-requests/{$id}/reviews";

        $this->getJson($history)->assertForbidden();
        $this->actingAs($admin)->getJson($history)->assertOk()
            ->assertJsonPath('total', 0)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->patchJson("/api/admin/privacy-requests/{$id}/review", [
            'expected_status' => 'pending',
            'status' => 'reviewing',
            'review_note' => 'Initial independent privacy identity verification.',
        ])->assertOk();

        $this->patchJson("/api/admin/privacy-requests/{$id}/review", [
            'expected_status' => 'reviewing',
            'status' => 'blocked_external',
            'review_note' => 'Pending third party retention and legal approval.',
        ])->assertOk();

        $this->assertDatabaseCount('account_privacy_request_reviews', 2);
        $records = DB::table('account_privacy_request_reviews')->orderBy('id')->get();
        $this->assertSame('pending', $records[0]->from_status);
        $this->assertSame('reviewing', $records[0]->to_status);
        $this->assertSame('reviewing', $records[1]->from_status);
        $this->assertSame('blocked_external', $records[1]->to_status);
        $this->assertStringNotContainsString(
            'Initial independent privacy identity verification',
            $records[0]->note_encrypted
        );
        $this->assertStringNotContainsString(
            'Pending third party retention',
            $records[1]->note_encrypted
        );

        $this->getJson($history)->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.to_status', 'blocked_external')
            ->assertJsonPath('data.0.note', 'Pending third party retention and legal approval.')
            ->assertJsonPath('data.1.from_status', 'pending')
            ->assertJsonPath('data.1.note', 'Initial independent privacy identity verification.')
            ->assertJsonMissingPath('data.0.note_encrypted');

        $this->actingAs($customer)->getJson($history)->assertForbidden();
        $this->getJson('/api/account/privacy-requests')->assertOk()
            ->assertJsonPath('data.0.status', 'blocked_external')
            ->assertDontSee('Pending third party retention');
    }

    public function test_failed_review_does_not_append_a_private_history_record(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($customer)->postJson(
            '/api/account/privacy-requests', $this->submit('correction')
        )->assertCreated();
        $id = DB::table('account_privacy_requests')->value('id');
        $this->actingAs($admin)->patchJson(
            "/api/admin/privacy-requests/{$id}/review",
            [
                'expected_status' => 'reviewing',
                'status' => 'closed_review',
                'review_note' => 'Incorrect prior status; must not append evidence.',
            ]
        )->assertStatus(409);
        $this->assertDatabaseCount('account_privacy_request_reviews', 0);
        $this->assertDatabaseHas('account_privacy_requests', [
            'id' => $id, 'status' => 'pending',
        ]);
    }

    public function test_non_admin_and_self_review_are_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->postJson('/api/account/privacy-requests', $this->submit())
            ->assertCreated();
        $id = DB::table('account_privacy_requests')->value('id');
        $payload = ['expected_status' => 'pending', 'status' => 'reviewing',
            'review_note' => 'Case review with evidence and legal constraints.'];
        $this->patchJson('/api/admin/privacy-requests/'.$id.'/review', $payload)
            ->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/privacy-requests')
            ->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($admin)->postJson('/api/account/privacy-requests',
            $this->submit('access', 'Please provide my own Admin data export request.'))
            ->assertCreated();
        $ownId = DB::table('account_privacy_requests')
            ->where('user_id', $admin->id)->value('id');
        $this->patchJson('/api/admin/privacy-requests/'.$ownId.'/review', $payload)
            ->assertForbidden();
    }

    public function test_invalid_input_and_unpermitted_state_changes_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $this->actingAs($user)->postJson('/api/account/privacy-requests',
            $this->submit('unknown'))->assertUnprocessable();
        $this->postJson('/api/account/privacy-requests',
            $this->submit('erasure', 'short'))->assertUnprocessable();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\TrimStrings::class);
        $this->postJson('/api/account/privacy-requests',
            $this->submit('erasure', '        short        '))
            ->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertDatabaseCount('account_privacy_requests', 0);

        $this->postJson('/api/account/privacy-requests',
            $this->submit('erasure', 'A reasonable privacy review request.'))->assertCreated();
        $id = DB::table('account_privacy_requests')->value('id');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson('/api/admin/privacy-requests/'.$id.'/review', [
            'expected_status' => 'pending',
            'status' => 'reviewing',
            'review_note' => '         short         ',
        ])->assertUnprocessable()->assertJsonValidationErrors('review_note');
        $this->assertDatabaseCount('account_privacy_request_reviews', 0);
        $this->patchJson('/api/admin/privacy-requests/'.$id.'/review', [
            'expected_status' => 'pending',
            'status' => 'closed_review',
            'review_note' => 'Attempt to claim completion without review.',
        ])->assertStatus(409);
        $this->getJson('/api/admin/privacy-requests?status=invalid')
            ->assertUnprocessable();
    }
}
