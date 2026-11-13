<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $phone = '+971501234567';

    protected function setUp(): void
    {
        parent::setUp();
        config(['phone_verification.enabled' => true, 'phone_verification.account_sid' => 'AC'.str_repeat('a', 32),
            'phone_verification.service_sid' => 'VA'.str_repeat('b', 32), 'phone_verification.auth_token' => str_repeat('test-only-', 4)]);
        Http::preventStrayRequests();
    }

    private function payload(string $status = 'pending', array $overrides = []): array
    {
        return array_replace(['account_sid' => config('phone_verification.account_sid'),
            'service_sid' => config('phone_verification.service_sid'), 'sid' => 'VE'.str_repeat('c', 32),
            'channel' => 'sms', 'status' => $status, 'to' => $this->phone], $overrides);
    }

    private function start(?string $key = null, array $overrides = [])
    {
        return $this->postJson('/api/account/phone/challenges', array_replace(['phone' => $this->phone,
            'password' => 'password', 'consent' => true, 'locale' => 'ar', 'idempotency_key' => $key ?? (string) Str::uuid()], $overrides));
    }

    private function confirm(string $id, string $code = '123456')
    {
        return $this->postJson('/api/account/phone/challenges/'.$id.'/confirm', ['code' => $code]);
    }

    public function test_delivery_is_disabled_without_config_and_phone_is_not_a_login_factor(): void
    {
        config(['phone_verification.enabled' => false]);
        $this->actingAs(User::factory()->create())->getJson('/api/account/phone')->assertOk()
            ->assertJsonPath('available', false)->assertJsonPath('verified', false)->assertJsonPath('login_factor', false);
        $this->start()->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('account_phone_challenges', 0);
    }

    public function test_explicit_request_and_approval_encrypt_phone_and_do_not_expose_provider_or_code(): void
    {
        $account = User::factory()->create();
        $this->actingAs($account);
        Http::fakeSequence()->push($this->payload())->push($this->payload('approved'));
        $reply = $this->start()->assertAccepted()->assertJsonPath('status', 'pending')->assertHeader('Cache-Control', 'no-store, private');
        $id = $reply->json('id');
        $this->assertStringNotContainsString($this->phone, $reply->getContent());
        $this->assertStringNotContainsString('VE'.str_repeat('c', 32), $reply->getContent());
        $raw = DB::table('account_phone_challenges')->where('id', $id)->first();
        $this->assertStringNotContainsString($this->phone, $raw->phone_ciphertext);
        $this->confirm($id)->assertOk()->assertJsonPath('verified', true);
        $this->assertSame($this->phone, $account->fresh()->phone_e164);
        $this->assertStringNotContainsString($this->phone, DB::table('users')->where('id', $account->id)->value('phone_e164'));
        $this->assertNull(DB::table('account_phone_challenges')->where('id', $id)->value('phone_ciphertext'));
        $this->getJson('/api/me')->assertJsonMissingPath('phone_e164')->assertJsonMissingPath('phone_verified_at');
        $this->getJson('/api/account/phone')->assertJsonPath('verified', true)->assertJsonPath('phone_mask', '••••4567');
        $this->confirm($id)->assertConflict();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Verifications') && $r['To'] === $this->phone && $r['Locale'] === 'ar');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/VerificationCheck') && $r['VerificationSid'] === $raw->provider_sid && $r['Code'] === '123456');
        $this->assertStringNotContainsString($this->phone, json_encode(DB::table('audit_events')->get()));
        $this->assertStringNotContainsString('123456', json_encode(DB::table('audit_events')->get()));
    }

    public function test_idempotency_and_uncertain_delivery_never_resend_automatically(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['*' => Http::response(['private' => 'provider diagnostic'], 500)]);
        $key = (string) Str::uuid();
        $id = $this->start($key)->assertAccepted()->assertJsonPath('status', 'uncertain')->json('id');
        $this->start($key)->assertAccepted()->assertJsonPath('id', $id);
        $this->start($key, ['phone' => '+971509999999'])->assertConflict();
        $this->start()->assertStatus(429);
        $this->confirm($id)->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_password_consent_and_number_validation_precede_delivery(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([['password' => 'incorrect'], ['consent' => false], ['phone' => '0501234567'], ['phone' => '+971 50 1234567'], ['locale' => 'bad']] as $bad) {
            $this->start(null, $bad)->assertUnprocessable();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('account_phone_challenges', 0);
    }

    public function test_code_attempts_are_bounded_and_expired_payloads_are_removed(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['*' => Http::response($this->payload())]);
        $id = $this->start()->assertAccepted()->json('id');
        for ($i = 0; $i < 5; $i++) {
            $this->confirm($id, '000000')->assertOk()->assertJsonPath('verified', false)->assertJsonPath('attempts_remaining', 4 - $i);
        }
        $this->confirm($id)->assertConflict();
        $this->assertNull(DB::table('account_phone_challenges')->where('id', $id)->value('phone_ciphertext'));
        Http::assertSentCount(6);
        $this->travel(2)->minutes();
        $next = $this->start()->assertAccepted()->json('id');
        $this->travel(11)->minutes();
        $this->confirm($next)->assertConflict();
        $this->artisan('accounts:expire-phone-challenges')->assertSuccessful();
        $this->assertDatabaseHas('account_phone_challenges', ['id' => $next, 'phone_ciphertext' => null, 'provider_sid' => null, 'status' => 'expired']);
    }

    public function test_foreign_identity_and_revoked_epoch_cannot_confirm(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        Http::fake(['*' => Http::response($this->payload())]);
        $id = $this->start()->assertAccepted()->json('id');
        $this->actingAs(User::factory()->create());
        $this->confirm($id)->assertNotFound();
        $owner->forceFill(['auth_epoch' => 1])->save();
        $this->actingAs($owner)->withSession(['account_auth_epoch' => 1]);
        $this->confirm($id)->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_confused_provider_approval_and_expiry_during_check_never_verify(): void
    {
        $this->actingAs(User::factory()->create());
        $checks = 0;
        Http::fake(function ($request) use (&$checks) {
            if (str_ends_with($request->url(), '/Verifications')) {
                return Http::response($this->payload());
            }
            if (++$checks === 1) {
                return Http::response($this->payload('approved', ['to' => '+971509999999']));
            }
            $this->travel(11)->minutes();

            return Http::response($this->payload('approved'));
        });
        $id = $this->start()->assertAccepted()->json('id');
        $this->confirm($id)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('check_uncertain', true);
        $this->confirm($id)->assertOk()->assertJsonPath('verified', false)->assertJsonPath('status', 'expired');
    }

    public function test_native_routes_share_verification_and_do_not_accept_admin_or_guest_identity(): void
    {
        $this->getJson('/api/v1/account/phone')->assertUnauthorized();
        $account = User::factory()->create();
        $token = $account->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
        Http::fakeSequence()->push($this->payload())->push($this->payload('approved'));
        $this->withToken($token)->getJson('/api/v1/account/phone')->assertOk()->assertJsonPath('verified', false);
        $id = $this->postJson('/api/v1/account/phone/challenges', ['phone' => $this->phone, 'password' => 'password',
            'consent' => true, 'locale' => 'en', 'idempotency_key' => (string) Str::uuid()])->assertAccepted()->json('id');
        $this->postJson('/api/v1/account/phone/challenges/'.$id.'/confirm', ['code' => '123456'])
            ->assertOk()->assertJsonPath('verified', true);
        $this->getJson('/api/v1/me')->assertJsonMissingPath('phone_e164');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/account/phone')->assertForbidden();
    }

    public function test_target_delivery_limit_applies_across_accounts_without_leaking_phone_data(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs(User::factory()->create());
            $response = $this->start();
            $response->assertStatus($i === 3 ? 429 : 202);
        }
        Http::assertSentCount(3);
    }

    public function test_private_phone_can_be_removed_only_by_its_owner_after_password_confirmation(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['phone_e164' => $this->phone, 'phone_verified_at' => now()])->save();
        $this->actingAs($owner)->deleteJson('/api/account/phone', ['password' => 'incorrect'])->assertUnprocessable();
        $this->assertSame($this->phone, $owner->fresh()->phone_e164);
        $this->deleteJson('/api/account/phone', ['password' => 'password'])->assertNoContent();
        $this->assertNull($owner->fresh()->phone_e164);
        $this->assertNull($owner->fresh()->phone_verified_at);
    }
}
