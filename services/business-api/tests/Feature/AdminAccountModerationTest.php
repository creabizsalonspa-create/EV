<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccountModerationTest extends TestCase
{
    use RefreshDatabase;

    private function decision(string $status, string $expected = 'active', string $reason = 'Verified security review evidence'): array
    {
        return [
            'expected_status' => $expected,
            'account_status' => $status,
            'reason' => $reason,
        ];
    }

    public function test_only_active_admin_can_moderate_non_admin_and_read_private_history(): void
    {
        $target = User::factory()->create(['role' => 'vendor']);
        $route = "/api/admin/accounts/{$target->id}/moderate";
        $history = "/api/admin/accounts/{$target->id}/moderations";

        $this->patchJson($route, $this->decision('suspended'))->assertUnauthorized();
        $this->getJson($history)->assertUnauthorized();

        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->patchJson($route, $this->decision('suspended'))->assertForbidden();
            $this->getJson($history)->assertForbidden();
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson($route, $this->decision('suspended'))
            ->assertOk()->assertJsonPath('account_status', 'suspended')
            ->assertHeader('Cache-Control', 'no-store, private');

        $private = DB::table('admin_account_moderations')->first();
        $this->assertNotNull($private);
        $this->assertStringNotContainsString(
            'Verified security review evidence', $private->reason_encrypted
        );
        $reply = $this->getJson($history)->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.actor_user_id', $admin->id)
            ->assertJsonPath('data.0.from_status', 'active')
            ->assertJsonPath('data.0.to_status', 'suspended')
            ->assertJsonPath('data.0.reason', 'Verified security review evidence');
        $this->assertStringContainsString('no-store', $reply->headers->get('Cache-Control'));
    }

    public function test_suspension_revokes_tokens_and_blocks_web_native_and_new_sessions(): void
    {
        $target = User::factory()->create(['role' => 'vendor']);
        $token = $target->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        DB::table('sessions')->insert([
            'id' => 'session-to-revoke',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->withHeaders(['Authorization' => ''])->actingAs($admin, 'web')
            ->patchJson("/api/admin/accounts/{$target->id}/moderate", $this->decision('suspended'))
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'account_status' => 'suspended',
        ]);
        $this->assertNotNull($target->fresh()->suspended_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-revoke']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/v1/login', ['email' => $target->email, 'password' => 'password'])
            ->assertForbidden();
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'password'])
            ->assertUnprocessable();

        $this->actingAs($target->fresh(), 'web')->getJson('/api/vehicles')
            ->assertForbidden();
    }

    public function test_reinstate_requires_current_state_and_never_recovers_old_tokens(): void
    {
        $target = User::factory()->create(['role' => 'customer']);
        $token = $target->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
        $admin = User::factory()->create(['role' => 'admin']);
        $route = "/api/admin/accounts/{$target->id}/moderate";
        $this->actingAs($admin)->patchJson($route, $this->decision('suspended'))->assertOk();

        $this->patchJson($route, $this->decision('suspended'))
            ->assertStatus(409);
        $this->patchJson($route, $this->decision('active', 'suspended', 'Identity and security review completed'))
            ->assertOk()->assertJsonPath('account_status', 'active');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'account_status' => 'active',
            'suspended_at' => null,
        ]);
        $this->assertDatabaseCount('admin_account_moderations', 2);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/v1/login', ['email' => $target->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'customer');
    }

    public function test_administrator_can_never_suspend_self_or_another_administrator(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->patchJson("/api/admin/accounts/{$admin->id}/moderate", $this->decision('suspended'))
            ->assertForbidden();
        $this->patchJson("/api/admin/accounts/{$other->id}/moderate", $this->decision('suspended'))
            ->assertForbidden();
        $this->assertDatabaseCount('admin_account_moderations', 0);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'account_status' => 'active']);
    }

    public function test_moderation_requires_a_real_reason_and_rejects_stale_requests(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'vendor']);
        $route = "/api/admin/accounts/{$target->id}/moderate";
        $this->actingAs($admin)->patchJson($route, $this->decision('suspended', 'active', 'short'))
            ->assertUnprocessable();
        $this->patchJson($route, $this->decision('active'))->assertUnprocessable();
        $this->patchJson($route, $this->decision('active', 'suspended'))
            ->assertStatus(409);
        $this->assertDatabaseCount('admin_account_moderations', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_old_browser_session_does_not_reactivate_after_reinstatement(): void
    {
        $account = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $route = "/api/admin/accounts/{$account->id}/moderate";
        $this->actingAs($admin)->patchJson($route, $this->decision('suspended'))->assertOk();
        $this->patchJson($route, $this->decision('active', 'suspended',
            'Review confirmed and account restored'))->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $account->id, 'account_status' => 'active', 'auth_epoch' => 2,
        ]);

        // Simulate an old still-stored browser session, as can happen with a
        // file or distributed session driver. Role and status alone are not
        // enough: this stale authorization must not survive reinstatement.
        $this->actingAs($account->fresh(), 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/vehicles')->assertUnauthorized();
        $this->postJson('/api/login', [
            'email' => $account->email, 'password' => 'password',
        ])->assertOk();
        $this->getJson('/api/vehicles')->assertOk();
    }

    public function test_upgrading_account_role_invalidates_old_browser_authorization_version(): void
    {
        $account = User::factory()->create(['role' => 'customer']);
        $this->assertSame(0, $account->auth_epoch);
        // Production KYC approval must save the locked Eloquent model so the
        // role transition invalidation event fires; bulk SQL skips it.
        $account->forceFill(['role' => 'vendor'])->save();
        $this->assertSame(1, $account->fresh()->auth_epoch);

        $this->actingAs($account->fresh(), 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/vehicles')->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => $account->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('role', 'vendor');
        $this->getJson('/api/vehicles')->assertOk();
    }

    public function test_cookie_authenticated_native_api_cannot_bypass_stale_browser_epoch(): void
    {
        $account = User::factory()->create(['role' => 'customer']);
        $account->forceFill(['role' => 'vendor'])->save();
        $this->assertSame(1, $account->fresh()->auth_epoch);

        // Sanctum's first-party cookie mode must not skip browser-epoch
        // invalidation merely because the requested route starts /api/v1/.
        $this->actingAs($account->fresh(), 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/v1/me')->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => $account->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('role', 'vendor');
        // The browser login is a web-session flow; this deployment does not
        // promise that a browser cookie authenticates native Sanctum routes.
        $this->getJson('/api/vehicles')->assertOk();
    }

    public function test_new_genuine_native_token_is_compatible_with_nonzero_browser_epoch(): void
    {
        $account = User::factory()->create(['role' => 'customer']);
        $account->forceFill(['auth_epoch' => 3])->save();
        $token = $account->createToken('mobile', ['platform'], now()->addDay())
            ->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('role', 'customer');
    }

    public function test_account_directory_can_filter_moderation_state_without_exposing_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($admin)
            ->patchJson("/api/admin/accounts/{$target->id}/moderate", $this->decision('suspended', 'active', 'Private investigation ticket 4820'))
            ->assertOk();

        $this->getJson('/api/admin/accounts?account_status=suspended')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.account_status', 'suspended')
            ->assertDontSee('Private investigation ticket 4820');

        $this->getJson('/api/admin/accounts?account_status=unexpected')
            ->assertUnprocessable();
    }
}
