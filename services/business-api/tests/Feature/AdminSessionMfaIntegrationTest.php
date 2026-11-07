<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSessionMfaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_revoke_all_requires_a_fresh_admin_factor_when_mfa_is_installed(): void
    {
        // PR72 is independently testable before Agent4 integrates PR80. This
        // cross-PR acceptance test executes in the combined source tree, and
        // explicitly reports the missing integration dependency in isolation.
        if (! is_file(app_path('Security/AdminTotp.php'))) {
            $this->markTestSkipped('Admin TOTP source from PR80 is not yet integrated.');
        }
        config()->set('admin_security.enforce_in_local_tests', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $oldToken = $admin->createToken('prior-mobile', ['platform'], now()->addDay())
            ->accessToken->id;

        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('admin_second_factor_required', true);

        // A correctly supplied password alone must not bypass mandatory
        // Admin TOTP, including on this self-revocation endpoint.
        $this->postJson('/api/admin/sessions/revoke-all', ['password' => 'password'])
            ->assertForbidden()->assertJsonPath('admin_second_factor_required', true);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $oldToken]);
        $this->assertDatabaseCount('audit_events', 0);

        $secret = $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'password',
        ])->assertOk()->json('secret');
        $this->postJson('/api/admin/second-factor/confirm', [
            'code' => \App\Security\AdminTotp::codeAtStep(
                $secret, intdiv(now()->timestamp, 30)
            ),
        ])->assertOk()->assertJsonPath('verified', true);

        $this->postJson('/api/admin/sessions/revoke-all', ['password' => 'password'])
            ->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken]);
        $this->assertSame(1, $admin->fresh()->auth_epoch);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.sessions.revoked_all',
            'subject' => (string) $admin->id,
        ]);
        $this->getJson('/api/admin/overview')->assertUnauthorized();

        // A new password login is not an MFA grant; authenticator is still
        // enrolled and a fresh code will be required on the next device.
        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('admin_second_factor_required', true);
        $this->getJson('/api/admin/overview')->assertForbidden()
            ->assertJsonPath('admin_second_factor_required', true);
        $this->assertDatabaseCount('admin_second_factors', 1);
    }
}
