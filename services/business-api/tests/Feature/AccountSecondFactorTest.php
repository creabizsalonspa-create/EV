<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\AdminTotp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSecondFactorTest extends TestCase
{
    use RefreshDatabase;

    private function setupFactor(User $user): array
    {
        $this->actingAs($user);
        $prepared = $this->postJson('/api/account/second-factor/prepare', ['password' => 'password'])->assertOk()->json();
        $code = AdminTotp::codeAtStep($prepared['secret'], intdiv(now()->timestamp, 30));
        $reply = $this->postJson('/api/account/second-factor/enable', ['password' => 'password', 'code' => $code])->assertOk()->json();

        return [$prepared['secret'], $reply['recovery_codes']];
    }

    public function test_enrollment_requires_password_and_authenticator_proof_and_stores_only_encrypted_secrets(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/account/second-factor/prepare', ['password' => 'incorrect'])->assertUnprocessable();
        $prepared = $this->postJson('/api/account/second-factor/prepare', ['password' => 'password'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $row = DB::table('account_second_factors')->where('user_id', $user->id)->first();
        $this->assertNotSame($prepared['secret'], $row->pending_secret);
        $this->getJson('/api/account/second-factor')->assertJsonPath('enabled', false)->assertJsonPath('pending', true)->assertJsonMissingPath('secret');
        $this->postJson('/api/account/second-factor/enable', ['password' => 'password', 'code' => 'wrong'])->assertUnprocessable();
        $code = AdminTotp::codeAtStep($prepared['secret'], intdiv(now()->timestamp, 30));
        $reply = $this->postJson('/api/account/second-factor/enable', ['password' => 'password', 'code' => $code])->assertOk()->json();
        $this->assertCount(8, $reply['recovery_codes']);
        $this->assertFalse($reply['reauthentication_required']);
        $row = DB::table('account_second_factors')->where('user_id', $user->id)->first();
        $this->assertSame($prepared['secret'], Crypt::decryptString($row->secret));
        $this->assertNull($row->pending_secret);
        $this->assertStringNotContainsString($reply['recovery_codes'][0], $row->recovery_hashes);
        $this->getJson('/api/me')->assertOk(); // Proven current browser keeps a fresh epoch.
        $this->getJson('/api/account/second-factor')->assertJsonPath('enabled', true)->assertJsonPath('recovery_remaining', 8)->assertJsonMissingPath('recovery_codes');
        $this->assertStringNotContainsString($prepared['secret'], json_encode(DB::table('audit_events')->get()));
    }

    public function test_password_only_and_replayed_totp_cannot_leave_an_authenticated_browser_session(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        [$secret] = $this->setupFactor($user);
        $this->app['auth']->forgetGuards();
        $login = ['email' => $user->email, 'password' => 'password'];
        $this->postJson('/api/login', $login)->assertUnprocessable()->assertJsonValidationErrors('second_factor_code');
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', $login + ['second_factor_code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30))])->assertUnprocessable();
        $this->travel(31)->seconds();
        $code = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
        $this->postJson('/api/login', $login + ['second_factor_code' => $code])->assertOk();
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', $login + ['second_factor_code' => $code])->assertUnprocessable();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_native_bearer_login_requires_factor_and_recovery_is_single_use(): void
    {
        $user = User::factory()->create(['role' => 'vendor']);
        [, $codes] = $this->setupFactor($user);
        $this->app['auth']->forgetGuards();
        $login = ['email' => $user->email, 'password' => 'password'];
        $this->postJson('/api/v1/login', $login)->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/login', $login + ['second_factor_code' => strtolower($codes[0])])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/login', $login + ['second_factor_code' => $codes[0]])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->actingAs($user->fresh())->getJson('/api/account/second-factor')->assertJsonPath('recovery_remaining', 7);
    }

    public function test_enabling_revokes_other_tokens_and_browser_epochs_but_never_other_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('old')->accessToken->id;
        $otherToken = $other->createToken('other')->accessToken->id;
        $this->setupFactor($user);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken]);
        $this->assertSame(1, $user->fresh()->auth_epoch);
        $this->actingAs($user->fresh())->withSession(['account_auth_epoch' => 0])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_expired_or_pre_revocation_setup_cannot_be_enabled_and_payload_cleanup_is_bounded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $seed = $this->postJson('/api/account/second-factor/prepare', ['password' => 'password'])->assertOk()->json('secret');
        $this->travel(11)->minutes();
        $code = AdminTotp::codeAtStep($seed, intdiv(now()->timestamp, 30));
        $this->postJson('/api/account/second-factor/enable', ['password' => 'password', 'code' => $code])->assertConflict();
        $this->artisan('accounts:expire-second-factor-setup')->assertSuccessful();
        $this->assertNull(DB::table('account_second_factors')->where('user_id', $user->id)->value('pending_secret'));
        $seed = $this->postJson('/api/account/second-factor/prepare', ['password' => 'password'])->assertOk()->json('secret');
        $user->forceFill(['auth_epoch' => 1])->save();
        $this->actingAs($user)->withSession(['account_auth_epoch' => 1]);
        $this->postJson('/api/account/second-factor/enable', ['password' => 'password', 'code' => AdminTotp::codeAtStep($seed, intdiv(now()->timestamp, 30))])->assertConflict();
    }

    public function test_disable_and_recovery_rotation_require_current_factor_and_do_not_leak_old_codes(): void
    {
        $user = User::factory()->create();
        [, $codes] = $this->setupFactor($user);
        $this->postJson('/api/account/second-factor/disable', ['password' => 'password', 'code' => 'incorrect'])->assertUnprocessable();
        $rotated = $this->postJson('/api/account/second-factor/recovery-codes', ['password' => 'password', 'code' => $codes[0]])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $rotated);
        $this->postJson('/api/account/second-factor/disable', ['password' => 'password', 'code' => $codes[1]])->assertUnprocessable();
        $this->postJson('/api/account/second-factor/disable', ['password' => 'password', 'code' => $rotated[0]])->assertOk()->assertJsonPath('enabled', false);
        $this->assertDatabaseCount('account_second_factors', 0);
        $this->assertSame(3, $user->fresh()->auth_epoch);
    }

    public function test_bearer_enrollment_returns_one_time_codes_before_revoking_its_own_credential(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('native')->plainTextToken;
        $this->withToken($token);
        $seed = $this->postJson('/api/v1/account/second-factor/prepare', ['password' => 'password'])->assertOk()->json('secret');
        $reply = $this->postJson('/api/v1/account/second-factor/enable', ['password' => 'password',
            'code' => AdminTotp::codeAtStep($seed, intdiv(now()->timestamp, 30))])->assertOk()
            ->assertJsonPath('reauthentication_required', true)->json();
        $this->assertCount(8, $reply['recovery_codes']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/account/second-factor')->assertUnauthorized();
    }

    public function test_guests_admin_and_unenrolled_accounts_cannot_bypass_setup_or_factor_requirements(): void
    {
        $this->postJson('/api/account/second-factor/prepare', ['password' => 'password'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/api/account/second-factor/prepare', ['password' => 'password'])->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/account/second-factor/enable', ['password' => 'password', 'code' => '123456'])->assertConflict();
    }
}
