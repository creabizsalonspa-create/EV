<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_is_non_enumerating_and_reset_token_is_single_use(): void
    {
        Notification::fake();
        $u = User::factory()->create();
        $token = null;
        $this->postJson('/api/forgot-password', ['email' => $u->email])->assertAccepted()->assertExactJson(['message' => 'If eligible, a password reset link has been sent.']);
        Notification::assertSentTo($u, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->postJson('/api/forgot-password', ['email' => 'absent@example.test'])->assertAccepted()->assertExactJson(['message' => 'If eligible, a password reset link has been sent.']);
        $v = ['email' => $u->email, 'token' => $token, 'password' => 'new-long-test-password', 'password_confirmation' => 'new-long-test-password'];
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $this->postJson('/api/reset-password', $v)->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertTrue(Hash::check($v['password'], $u->fresh()->password));
        $this->postJson('/api/reset-password', $v)->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $u->email, 'password' => $v['password']])->assertOk();
    }

    public function test_password_reset_invalidates_old_browser_session_even_with_file_storage(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $oldToken = $user->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
        $this->assertSame(0, $user->auth_epoch);

        $reset = Password::createToken($user);
        $password = 'fresh-long-reset-password';
        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $reset,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();

        $this->assertSame(1, $user->fresh()->auth_epoch);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();

        // A stale file/Redis-backed session may still contain the old user
        // identifier. The updated authorization version must deny it even
        // when there is no SQL session row to delete.
        $this->withHeaders(['Authorization' => ''])
            ->actingAs($user->fresh(), 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/vehicles')->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();
        $this->getJson('/api/vehicles')->assertOk();
    }

    public function test_password_reset_invalidates_first_party_native_cookie_session(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $resetToken = Password::createToken($user);
        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'new-password-after-reset',
            'password_confirmation' => 'new-password-after-reset',
        ])->assertOk();
        $this->assertSame(1, $user->fresh()->auth_epoch);

        $this->actingAs($user->fresh(), 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_reset_rejects_wrong_user_and_weak_or_expired_token(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::createToken($u);
        $v = ['email' => $other->email, 'token' => $token, 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password'];
        $this->postJson('/api/reset-password', $v)->assertUnprocessable();
        $v['email'] = $u->email;
        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', $v)->assertUnprocessable();
    }

    public function test_profile_cannot_update_role_or_another_account(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($u)->patchJson('/api/profile', ['name' => 'New name', 'locale' => 'ar', 'role' => 'admin', 'id' => $other->id])->assertOk()->assertJsonPath('role', 'customer')->assertJsonPath('locale', 'ar');
        $this->assertNotSame('New name', $other->fresh()->name);
    }

    public function test_security_headers_and_private_api_cache_policy(): void
    {
        $this->get('/')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/stations')->assertHeader('Cache-Control', 'no-store, private');
    }
}
