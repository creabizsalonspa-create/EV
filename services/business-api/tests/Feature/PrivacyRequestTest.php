<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivacyRequestTest extends TestCase
{
    use RefreshDatabase;

    private function body(string $id = 'privacy-request-key-1'): array
    {
        return ['request_type' => 'account_deletion', 'reason' => 'I would like my account reviewed for deletion.', 'idempotency_key' => $id];
    }

    public function test_private_creation_idempotency_and_replay_conflict(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->postJson('/api/privacy-requests', $this->body())->assertUnauthorized();
        $this->actingAs($customer)->postJson('/api/privacy-requests', $this->body())->assertCreated()->assertJsonPath('status', 'submitted');
        $this->actingAs($customer)->postJson('/api/privacy-requests', $this->body())->assertOk();
        $conflict = $this->body();
        $conflict['request_type'] = 'data_access';
        $this->actingAs($customer)->postJson('/api/privacy-requests', $conflict)->assertStatus(409);
        $this->assertSame(1, PrivacyRequest::count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.request.submitted')->count());
        $this->assertNotSame($this->body()['reason'], DB::table('privacy_requests')->value('reason'));
    }

    public function test_user_isolation_and_withdrawn_request_cannot_be_reviewed(): void
    {
        $alice = User::factory()->create(['role' => 'customer']);
        $bob = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $row = $this->actingAs($alice)->postJson('/api/privacy-requests', $this->body())->json();
        $route = '/api/privacy-requests/'.$row['id'].'/withdraw';
        $this->actingAs($bob)->getJson('/api/privacy-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($bob)->postJson($route)->assertNotFound();
        $this->actingAs($alice)->postJson($route)->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->actingAs($alice)->postJson($route)->assertOk();
        $this->actingAs($admin)->patchJson('/api/admin/privacy-requests/'.$row['id'], ['status' => 'in_review'])->assertStatus(409);
    }

    public function test_admin_review_requires_authorization_and_documented_closure(): void
    {
        $alice = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($alice)->postJson('/api/privacy-requests', $this->body())->json('id');
        $route = '/api/admin/privacy-requests/'.$id;
        $this->actingAs($alice)->getJson('/api/admin/privacy-requests')->assertForbidden();
        $this->actingAs($alice)->patchJson($route, ['status' => 'in_review'])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/privacy-requests?status=submitted')
            ->assertOk()->assertJsonPath('data.0.user.email', $alice->email);
        $this->actingAs($admin)->patchJson($route, ['status' => 'closed'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($route, ['status' => 'in_review'])->assertOk()->assertJsonPath('status', 'in_review');
        $this->actingAs($admin)->patchJson($route, [
            'status' => 'closed', 'resolution' => 'referred',
            'decision_note' => 'Passed to the compliance queue for further handling.',
        ])->assertOk()->assertJsonPath('status', 'closed');
        $this->actingAs($alice)->getJson('/api/privacy-requests')->assertOk()->assertJsonPath('data.0.resolution', 'referred');
        $this->assertSame(2, DB::table('audit_events')->where('action', 'privacy.request.reviewed')->count());
        $this->assertNotSame('Passed to the compliance queue for further handling.', DB::table('privacy_requests')->value('decision_note'));
        $this->actingAs($alice)->postJson('/api/privacy-requests/'.$id.'/withdraw')->assertStatus(409);
    }

    public function test_native_api_uses_the_same_private_records(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/privacy-requests', $this->body('mobile-privacy-key-1'))
            ->assertCreated()->assertJsonPath('request_type', 'account_deletion');
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/privacy-requests')
            ->assertOk()->assertJsonPath('data.0.status', 'submitted');
    }
}
