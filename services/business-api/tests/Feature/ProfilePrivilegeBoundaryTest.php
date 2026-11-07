<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePrivilegeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_registration_does_not_accept_admin_role_or_security_field_injection(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Synthetic driver',
            'email' => 'synthetic-profile-user@example.test',
            'password' => 'test-only-password-1234',
            'locale' => 'ar',
            'role' => 'admin',
            'email_verified_at' => now()->toISOString(),
            'remember_token' => 'attacker-injected-remember-token',
            'id' => 1234567,
        ])->assertCreated()->assertJsonPath('role', 'customer')
            ->assertJsonPath('locale', 'ar')->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $account = User::findOrFail($response->json('id'));
        $this->assertSame('customer', $account->role);
        $this->assertFalse($account->hasVerifiedEmail());
        $this->assertNotSame('attacker-injected-remember-token', $account->getRememberToken());
        $this->assertNotSame(1234567, $account->id);
    }

    public function test_authenticated_web_profile_update_cannot_promote_role_or_replace_identity(): void
    {
        $account = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'vendor']);
        $password = $account->getAuthPassword();

        $this->actingAs($account)->patchJson('/api/profile', [
            'name' => 'Updated synthetic name',
            'locale' => 'ar',
            'role' => 'admin',
            'id' => $other->id,
            'email' => $other->email,
            'password' => 'attacker-changed-password',
            'email_verified_at' => now()->toISOString(),
        ])->assertOk()->assertJsonPath('role', 'customer')
            ->assertJsonPath('name', 'Updated synthetic name')
            ->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');

        $fresh = $account->fresh();
        $this->assertSame('customer', $fresh->role);
        $this->assertSame($account->email, $fresh->email);
        $this->assertSame($password, $fresh->getAuthPassword());
        $this->assertFalse($fresh->hasVerifiedEmail());
        $this->assertSame('vendor', $other->fresh()->role);
    }

    public function test_native_bearer_profile_update_cannot_promote_role_or_change_ownership(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('synthetic-security-test', ['platform'], now()->addHour())->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->patchJson('/api/v1/profile', [
            'name' => 'Synthetic native update',
            'locale' => 'en',
            'role' => 'admin',
            'id' => $other->id,
            'email' => $other->email,
            'password' => 'attacker-injected',
        ])->assertOk()->assertJsonPath('role', 'customer')
            ->assertJsonPath('name', 'Synthetic native update')
            ->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');

        $fresh = $user->fresh();
        $this->assertSame('customer', $fresh->role);
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame('admin', $other->fresh()->role);
    }
}
