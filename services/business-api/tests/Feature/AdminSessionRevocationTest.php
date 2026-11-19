<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function createSession(User $account, string $key): void
    {
        DB::table('sessions')->insert([
            'id' => $key,
            'user_id' => $account->id,
            'ip_address' => '127.0.0.1',
            'payload' => 'isolated-test-session',
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_only_active_admin_can_revoke_all_sessions(): void
    {
        $route = '/api/admin/sessions/revoke-all';

        $this->postJson($route, ['password' => 'password'])->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')
                ->postJson($route, ['password' => 'password'])
                ->assertForbidden();
        }
        $admin = User::factory()->create([
            'role' => 'admin', 'account_status' => 'suspended',
        ]);
        $this->actingAs($admin, 'web')
            ->postJson($route, ['password' => 'password'])
            ->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_wrong_password_or_invalid_input_preserves_sessions_tokens_and_epoch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tokenId = $admin->createToken('mobile', ['platform'], now()->addDay())
            ->accessToken->id;
        $this->createSession($admin, 'admin-wrong-password-session');
        $before = $admin->fresh()->remember_token;

        $this->actingAs($admin, 'web')
            ->postJson('/api/admin/sessions/revoke-all', ['password' => 'incorrect password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
        $this->postJson('/api/admin/sessions/revoke-all', ['password' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
        $this->postJson('/api/admin/sessions/revoke-all', ['password' => str_repeat('a', 129)])
            ->assertUnprocessable();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-wrong-password-session']);
        $this->assertSame(0, $admin->fresh()->auth_epoch);
        $this->assertSame($before, $admin->fresh()->remember_token);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_admin_password_confirmation_revokes_all_own_web_and_native_sessions_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'vendor']);
        $oldToken = $admin->createToken('mobile', ['platform'], now()->addDay())
            ->accessToken->id;
        $otherToken = $other->createToken('mobile', ['platform'], now()->addDay())
            ->accessToken->id;
        $this->createSession($admin, 'admin-session-one');
        $this->createSession($admin, 'admin-session-two');
        $this->createSession($other, 'other-account-session');
        $before = $admin->fresh()->remember_token;

        $this->actingAs($admin, 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->postJson('/api/admin/sessions/revoke-all', ['password' => 'password'])
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken]);
        $this->assertDatabaseMissing('sessions', ['id' => 'admin-session-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'admin-session-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-account-session']);
        $this->assertSame(1, $admin->fresh()->auth_epoch);
        $this->assertNotSame($before, $admin->fresh()->remember_token);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.sessions.revoked_all',
            'subject' => (string) $admin->id,
        ]);
        $this->assertDatabaseCount('audit_events', 1);
        $this->getJson('/api/me')->assertUnauthorized();

        // File and Redis sessions may linger beyond SQL deletion. An old
        // session cookie must still fail the version check.
        $this->actingAs($admin->fresh(), 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/admin/overview')
            ->assertUnauthorized();

        // New sign-in binds the new epoch and can access Admin again.
        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk();
        $this->getJson('/api/admin/overview')->assertOk();
    }

    public function test_revocation_rechecks_current_password_after_a_concurrent_change(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->forceFill(['password' => Hash::make('correct-new-password')])->save();

        $this->actingAs($admin, 'web')
            ->postJson('/api/admin/sessions/revoke-all', ['password' => 'password'])
            ->assertUnprocessable();
        $this->assertSame(0, $admin->fresh()->auth_epoch);
        $this->assertDatabaseCount('audit_events', 0);
        $this->postJson('/api/admin/sessions/revoke-all',
            ['password' => 'correct-new-password'])
            ->assertNoContent();
        $this->assertSame(1, $admin->fresh()->auth_epoch);
    }

    public function test_stale_admin_cannot_revoke_newer_sessions_after_demotion_or_credential_rotation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tokenId = $admin->createToken('prior-admin')->accessToken->id;
        $this->createSession($admin, 'stale-admin-revocation-proof');
        $route = '/api/admin/sessions/revoke-all';
        $this->actingAs($admin, 'web');

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->postJson($route, ['password' => 'password'])->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin', 'account_status' => 'suspended',
        ]);
        $this->postJson($route, ['password' => 'password'])->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active', 'auth_epoch' => 1,
        ]);
        $this->postJson($route, ['password' => 'password'])->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0,
            'password' => Hash::make('new-admin-password'),
        ]);
        // Knowing the current password does not restore a revoked
        // authenticated snapshot's ability to destroy newer sessions.
        $this->postJson($route, ['password' => 'new-admin-password'])
            ->assertStatus(409);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('sessions', ['id' => 'stale-admin-revocation-proof']);
        $this->assertSame(0, $admin->fresh()->auth_epoch);
        $this->assertDatabaseMissing('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.sessions.revoked_all',
        ]);
    }
}
