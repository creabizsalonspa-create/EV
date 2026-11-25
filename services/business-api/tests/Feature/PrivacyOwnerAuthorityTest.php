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

        DB::table('users')->where('id', $owner->id)->update(['password' => Hash::make('new-owner-password')]);
        $this->getJson($path)->assertStatus(409)->assertDontSee($payload['details']);
        $this->postJson($path, $payload)->assertStatus(409);
        $this->postJson($path, ['kind' => 'access', 'details' => 'Please review retained data.'])->assertStatus(409);
        $this->postJson($path.'/'.$id.'/withdraw')->assertStatus(409);
        $this->assertDatabaseCount('account_privacy_requests', 1);
        $this->assertDatabaseHas('account_privacy_requests', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseCount('audit_events', $auditCount);

        $this->actingAs($owner->fresh(), 'web')->postJson($path.'/'.$id.'/withdraw')
            ->assertOk()->assertJsonPath('status', 'withdrawn');
    }
}
