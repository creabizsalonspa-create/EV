<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrivacyOwnerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_owner_cannot_read_replay_create_or_withdraw_private_requests(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $path = '/api/account/privacy-requests';
        $payload = ['kind' => 'erasure', 'details' => 'Private request for account review.', 'idempotency_key' => 'owner-privacy-request-key'];
        $this->actingAs($owner, 'web')->postJson($path, $payload)->assertCreated();
        $id = DB::table('account_privacy_requests')->value('id');
        $auditCount = DB::table('audit_events')->count();

        $original = (array) DB::table('users')->where('id', $owner->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        foreach ([
            ['password' => Hash::make('new-owner-password')],
            ['auth_epoch' => (int) $original['auth_epoch'] + 1],
            ['role' => 'vendor'],
            ['account_status' => 'suspended'],
        ] as $change) {
            $this->actingAs($owner, 'web');
            DB::table('users')->where('id', $owner->id)->update(array_replace($original, $change));
            $status = isset($change['role']) || isset($change['account_status']) ? 403 : 409;
            $this->getJson($path)->assertStatus($status)->assertDontSee($payload['details']);
            $this->postJson($path, $payload)->assertStatus($status);
            $this->postJson($path, ['kind' => 'access', 'details' => 'Please review retained data.'])->assertStatus($status);
            $this->postJson($path.'/'.$id.'/withdraw')->assertStatus($status);
            $this->assertDatabaseCount('account_privacy_requests', 1);
            $this->assertDatabaseHas('account_privacy_requests', ['id' => $id, 'status' => 'pending']);
            $this->assertDatabaseCount('audit_events', $auditCount);
            DB::table('users')->where('id', $owner->id)->update($original);
        }

        $this->actingAs($owner->fresh(), 'web')->getJson($path.'?page=100001')->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.details', $payload['details']);

        $this->actingAs($owner->fresh(), 'web')->postJson($path.'/'.$id.'/withdraw')
            ->assertOk()->assertJsonPath('status', 'withdrawn');
    }
}
