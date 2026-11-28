<?php

namespace Tests\Feature;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use App\Security\AdminTotp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivacyErasureSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createCase(User $user, string $kind, string $status): AccountPrivacyRequest
    {
        return AccountPrivacyRequest::create([
            'user_id' => $user->id, 'kind' => $kind, 'status' => $status,
            'request_details_encrypted' => Crypt::encryptString('Please review my retained personal records.'),
        ]);
    }

    private function payload(): array
    {
        return ['confirmation' => 'discard_copies_and_revoke_sessions', 'password' => 'password'];
    }

    public function test_explicit_owner_security_step_discards_only_own_copies_and_revokes_sessions_without_erasure(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $erasure = $this->createCase($owner, 'erasure', 'pending');
        $ownCopy = $this->createCase($owner, 'access', 'reviewing');
        $foreignCopy = $this->createCase($other, 'access', 'reviewing');
        $this->actingAs($admin)->postJson('/api/admin/privacy-requests/'.$ownCopy->id.'/access-copy',
            ['password' => 'password', 'request_key' => 'owner-security-copy-1'])->assertCreated();
        $this->postJson('/api/admin/privacy-requests/'.$foreignCopy->id.'/access-copy',
            ['password' => 'password', 'request_key' => 'other-security-copy-1'])->assertCreated();
        $ownReceipt = DB::table('account_privacy_exports')->where('privacy_request_id', $ownCopy->id)->first();
        $foreignReceipt = DB::table('account_privacy_exports')->where('privacy_request_id', $foreignCopy->id)->first();
        $token = $owner->createToken('mobile')->accessToken->id;
        $otherToken = $other->createToken('mobile')->accessToken->id;
        DB::table('sessions')->insert(['id' => 'owner-session', 'user_id' => $owner->id, 'payload' => 'test', 'last_activity' => now()->timestamp]);
        DB::table('vehicles')->insert(['user_id' => $owner->id, 'name' => 'Retained vehicle', 'connector_type' => 'CCS2', 'created_at' => now(), 'updated_at' => now()]);
        $url = '/api/account/privacy-requests/'.$erasure->id.'/secure-account';
        $this->actingAs($other)->postJson($url, $this->payload())->assertNotFound();
        $this->actingAs($owner, 'web')->postJson($url, ['password' => 'password'])->assertUnprocessable();
        $this->postJson($url, array_replace($this->payload(), ['password' => 'wrong']))->assertUnprocessable();
        $this->assertSame(0, $owner->fresh()->auth_epoch);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token]);
        $this->postJson($url, $this->payload())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('derived_copies_discarded', 1)->assertJsonPath('sessions_revoked', true)
            ->assertJsonPath('sign_in_required', true)->assertJsonPath('source_records_deleted', false)
            ->assertJsonPath('automated_erasure_performed', false)->assertJsonPath('case_status', 'pending');
        $this->assertDatabaseHas('account_privacy_exports', ['id' => $ownReceipt->id, 'payload_encrypted' => null, 'sha256' => $ownReceipt->sha256]);
        $this->assertDatabaseHas('account_privacy_exports', ['id' => $foreignReceipt->id, 'payload_encrypted' => $foreignReceipt->payload_encrypted]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken]);
        $this->assertDatabaseMissing('sessions', ['id' => 'owner-session']);
        $this->assertSame(1, $owner->fresh()->auth_epoch);
        $this->assertSame(0, $other->fresh()->auth_epoch);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'account_status' => 'active']);
        $this->assertDatabaseHas('vehicles', ['user_id' => $owner->id, 'name' => 'Retained vehicle']);
        $this->assertDatabaseHas('account_privacy_requests', ['id' => $erasure->id, 'status' => 'pending']);
        $this->assertDatabaseCount('account_privacy_request_reviews', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'privacy.erasure.security_prepared', 'subject' => (string) $erasure->id]);
    }

    public function test_native_security_step_requires_enabled_factor_and_rejects_closed_or_non_erasure_case(): void
    {
        $owner = User::factory()->create();
        $case = $this->createCase($owner, 'erasure', 'reviewing');
        $closed = $this->createCase($owner, 'erasure', 'withdrawn');
        $access = $this->createCase($owner, 'access', 'reviewing');
        $secret = AdminTotp::generateSecret();
        DB::table('account_second_factors')->insert([
            'user_id' => $owner->id, 'secret' => Crypt::encryptString($secret), 'recovery_hashes' => Crypt::encryptString('[]'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $owner->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
        $this->withToken($token);
        foreach ([$closed, $access] as $invalid) {
            $this->postJson('/api/v1/privacy-requests/'.$invalid->id.'/secure-account', $this->payload())->assertStatus(409);
        }
        $url = '/api/v1/privacy-requests/'.$case->id.'/secure-account';
        $this->postJson($url, $this->payload())->assertUnprocessable();
        $this->assertSame(0, $owner->fresh()->auth_epoch);
        $this->postJson($url, $this->payload() + ['second_factor_code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30))])
            ->assertOk()->assertJsonPath('derived_copies_discarded', 0)->assertJsonPath('source_records_deleted', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('account_privacy_requests', ['id' => $case->id, 'status' => 'reviewing']);
    }
}
