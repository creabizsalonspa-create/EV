<?php

namespace Tests\Feature;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ported PR60 scenarios against the single canonical privacy workflow. */
class PrivacyRequestTest extends TestCase
{
    use RefreshDatabase;

    private function body(string $key = 'privacy-request-key-1'): array
    {
        return ['kind' => 'erasure', 'details' => 'Please review my account deletion request.', 'idempotency_key' => $key];
    }

    public function test_private_creation_idempotency_and_replay_conflict(): void
    {
        $customer = User::factory()->create();
        $url = '/api/account/privacy-requests';
        $this->postJson($url, $this->body())->assertUnauthorized();
        $created = $this->actingAs($customer)->postJson($url, $this->body())->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('automated_erasure_performed', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url, $this->body())->assertOk()->assertJsonPath('id', $created->json('id'));
        $changed = $this->body();
        $changed['kind'] = 'access';
        $this->postJson($url, $changed)->assertConflict();
        $changed = $this->body();
        $changed['details'] = 'Different private request explanation.';
        $this->postJson($url, $changed)->assertConflict();
        $this->assertSame(1, AccountPrivacyRequest::count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.requested.erasure')->count());
        $this->assertStringNotContainsString($this->body()['details'], DB::table('account_privacy_requests')->value('request_details_encrypted'));
    }

    public function test_user_isolation_and_withdrawn_request_cannot_be_reviewed(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($alice)->postJson('/api/account/privacy-requests', $this->body())->json('id');
        $route = '/api/account/privacy-requests/'.$id.'/withdraw';
        $this->actingAs($bob)->getJson('/api/account/privacy-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($route)->assertNotFound();
        $this->actingAs($alice)->postJson($route)->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->postJson($route)->assertOk();
        $this->actingAs($admin)->patchJson('/api/admin/privacy-requests/'.$id.'/review', [
            'expected_status' => 'pending', 'status' => 'reviewing', 'review_note' => 'Independent review of account request.',
        ])->assertConflict();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.request.withdrawn')->count());
        $this->assertDatabaseHas('users', ['id' => $alice->id]);
    }

    public function test_admin_review_requires_authorization_and_documented_closure(): void
    {
        $alice = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($alice)->postJson('/api/account/privacy-requests', $this->body())->json('id');
        $url = '/api/admin/privacy-requests/'.$id.'/review';
        $this->getJson('/api/admin/privacy-requests')->assertForbidden();
        $this->patchJson($url, [])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/privacy-requests?status=pending')->assertOk()
            ->assertJsonPath('data.0.owner.email', $alice->email);
        $this->patchJson($url, ['status' => 'closed_review'])->assertUnprocessable();
        $this->patchJson($url, ['expected_status' => 'pending', 'status' => 'reviewing', 'review_note' => 'Reviewing the requested privacy rights.'])->assertOk();
        foreach ([str_repeat(' ', 30), '   short   '] as $note) {
            $this->patchJson($url, ['expected_status' => 'reviewing', 'status' => 'closed_review', 'review_note' => $note])
                ->assertUnprocessable()->assertJsonValidationErrors('review_note');
        }
        $this->assertSame('reviewing', AccountPrivacyRequest::findOrFail($id)->status);
        $private = 'INTERNAL: compliance staff review documented for legal follow-up.';
        $this->patchJson($url, ['expected_status' => 'reviewing', 'status' => 'closed_review', 'review_note' => $private])->assertOk();
        $response = $this->actingAs($alice)->getJson('/api/account/privacy-requests')->assertOk()
            ->assertJsonPath('data.0.status', 'closed_review');
        $this->assertStringNotContainsString($private, $response->getContent());
        $this->assertSame(2, DB::table('account_privacy_request_reviews')->where('privacy_request_id', $id)->count());
        $this->assertStringNotContainsString($private, DB::table('account_privacy_requests')->value('review_note_encrypted'));
        $this->postJson('/api/account/privacy-requests/'.$id.'/withdraw')->assertConflict();
    }

    public function test_native_api_uses_the_same_private_records(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/privacy-requests', $this->body('mobile-privacy-key-1'))
            ->assertCreated()->assertJsonPath('kind', 'erasure');
        $id = AccountPrivacyRequest::firstOrFail()->id;
        $this->getJson('/api/v1/privacy-requests')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson('/api/v1/privacy-requests/'.$id.'/withdraw')->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->assertDatabaseCount('account_privacy_requests', 1);
        $this->assertDatabaseMissing('users', ['id' => -1]);
    }

    public function test_identity_is_account_scoped_and_closed_receipts_never_recreate_requests(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $a = $this->actingAs($alice)->postJson('/api/account/privacy-requests', $this->body())->json('id');
        $b = $this->actingAs($bob)->postJson('/api/account/privacy-requests', $this->body())->json('id');
        $this->assertNotSame($a, $b);
        $this->actingAs($alice)->postJson('/api/account/privacy-requests/'.$a.'/withdraw')->assertOk();
        $this->postJson('/api/account/privacy-requests', $this->body())->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->postJson('/api/account/privacy-requests', $this->body('another-explicit-request'))->assertCreated();
        $this->assertDatabaseCount('account_privacy_requests', 3);
    }

    public function test_owner_history_rejects_unbounded_or_invalid_pagination(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['0', '-1', '100001', '999999999999999999999', 'abc'] as $page) {
            $this->getJson('/api/account/privacy-requests?page='.$page)->assertUnprocessable();
        }
        $this->getJson('/api/account/privacy-requests?page=1')->assertOk()->assertJsonCount(0, 'data');
    }
}
