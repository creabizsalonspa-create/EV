<?php

namespace Tests\Feature;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use App\Security\AdminTotp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivacyContactMinimizationTest extends TestCase
{
    use RefreshDatabase;

    private function requestCase(User $owner, string $kind = 'erasure', string $status = 'pending'): AccountPrivacyRequest
    {
        return AccountPrivacyRequest::create(['user_id' => $owner->id, 'kind' => $kind, 'status' => $status,
            'request_details_encrypted' => Crypt::encryptString('Review my optional contact data and retained records.')]);
    }

    private function challenge(User $owner, string $status, ?string $phone): string
    {
        $id = (string) Str::uuid();
        DB::table('account_phone_challenges')->insert([
            'id' => $id, 'user_id' => $owner->id, 'status' => $status, 'auth_epoch' => $owner->auth_epoch,
            'idempotency_key' => (string) Str::uuid(), 'phone_hash' => hash('sha256', $id),
            'phone_ciphertext' => $phone === null ? null : Crypt::encryptString($phone),
            'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_owner_removes_optional_phone_and_live_payload_only_without_external_or_financial_mutation(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$owner, $other] as $account) {
            $account->forceFill(['phone_e164' => '+971501234567', 'phone_verified_at' => now()])->save();
        }
        $case = $this->requestCase($owner);
        $pending = $this->challenge($owner, 'pending', '+971501234567');
        $approved = $this->challenge($owner, 'approved', null);
        $foreign = $this->challenge($other, 'pending', '+971509999999');
        $foreignPayload = DB::table('account_phone_challenges')->where('id', $foreign)->value('phone_ciphertext');
        $hash = DB::table('account_phone_challenges')->where('id', $pending)->value('phone_hash');
        $url = '/api/account/privacy-requests/'.$case->id.'/minimize-contact';
        $payload = ['confirmation' => 'remove_optional_phone', 'password' => 'password'];
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs($other)->postJson($url, $payload)->assertNotFound();
        $this->actingAs($owner)->postJson($url, array_replace($payload, ['password' => 'wrong']))->assertUnprocessable();
        $this->postJson($url, $payload + ['user_id' => $other->id])->assertUnprocessable();
        $this->assertSame('+971501234567', $owner->fresh()->phone_e164);
        $response = $this->postJson($url, $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('optional_phone_removed', true)->assertJsonPath('profile_phone_changed', true)
            ->assertJsonPath('live_challenges_cancelled', 1)->assertJsonPath('challenge_payloads_cleared', 1)
            ->assertJsonPath('verification_receipts_retained', true)->assertJsonPath('external_deletion_verified', false)
            ->assertJsonPath('account_deleted', false)->assertJsonPath('automated_erasure_performed', false);
        $this->assertStringNotContainsString('+971501234567', $response->getContent());
        $this->assertNull($owner->fresh()->phone_e164);
        $this->assertNull($owner->fresh()->phone_verified_at);
        $this->assertSame('+971501234567', $other->fresh()->phone_e164);
        $this->assertDatabaseHas('account_phone_challenges', ['id' => $pending, 'status' => 'removed', 'phone_ciphertext' => null, 'phone_hash' => $hash]);
        $this->assertDatabaseHas('account_phone_challenges', ['id' => $approved, 'status' => 'approved']);
        $this->assertDatabaseHas('account_phone_challenges', ['id' => $foreign, 'status' => 'pending', 'phone_ciphertext' => $foreignPayload]);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('profile_phone_changed', false)->assertJsonPath('live_challenges_cancelled', 0);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.erasure.optional_contact_minimized')->count());
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => $owner->email, 'name' => $owner->name, 'account_status' => 'active']);
        $this->assertDatabaseHas('account_privacy_requests', ['id' => $case->id, 'status' => 'pending']);
        $this->assertDatabaseCount('finance_journals', 0);
        Http::assertNothingSent();
    }

    public function test_excessive_challenge_payloads_fail_closed_without_partial_minimization(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $owner->forceFill(['phone_e164' => '+971501234567', 'phone_verified_at' => now()])->save();
        $case = $this->requestCase($owner);
        for ($i = 0; $i < 251; $i++) {
            $this->challenge($owner, 'uncertain', '+971501234567');
        }
        $this->actingAs($owner)->postJson('/api/account/privacy-requests/'.$case->id.'/minimize-contact', [
            'confirmation' => 'remove_optional_phone', 'password' => 'password',
        ])->assertStatus(409);
        $this->assertSame('+971501234567', $owner->fresh()->phone_e164);
        $this->assertSame(251, DB::table('account_phone_challenges')->where('user_id', $owner->id)
            ->where('status', 'uncertain')->whereNotNull('phone_ciphertext')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'privacy.erasure.optional_contact_minimized')->count());
        Http::assertNothingSent();
    }

    public function test_native_requires_current_factor_and_open_erasure_case(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['phone_e164' => '+971501234567', 'phone_verified_at' => now()])->save();
        $case = $this->requestCase($owner);
        $closed = $this->requestCase($owner, 'erasure', 'withdrawn');
        $access = $this->requestCase($owner, 'access');
        $secret = AdminTotp::generateSecret();
        DB::table('account_second_factors')->insert([
            'user_id' => $owner->id, 'secret' => Crypt::encryptString($secret),
            'recovery_hashes' => Crypt::encryptString('[]'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withToken($owner->createToken('mobile', ['platform'], now()->addDay())->plainTextToken);
        $payload = ['confirmation' => 'remove_optional_phone', 'password' => 'password'];
        foreach ([$closed, $access] as $invalid) {
            $this->postJson('/api/v1/privacy-requests/'.$invalid->id.'/minimize-contact', $payload)->assertStatus(409);
        }
        $url = '/api/v1/privacy-requests/'.$case->id.'/minimize-contact';
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame('+971501234567', $owner->fresh()->phone_e164);
        $this->postJson($url, $payload + ['second_factor_code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30))])
            ->assertOk()->assertJsonPath('optional_phone_removed', true);
        $this->assertNull($owner->fresh()->phone_e164);
        $this->assertSame(0, $owner->fresh()->auth_epoch);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
