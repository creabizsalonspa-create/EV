<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\AdminTotp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSecondFactorCurrentAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('admin_security.enforce_in_local_tests', true);
    }

    public function test_private_admin_authenticator_setup_page_and_status_require_current_authority(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');
        $this->get('/admin/second-factor')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->getJson('/api/admin/second-factor/status')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('enrolled', false);

        // The browser authentication snapshot remains Admin throughout;
        // a revoked live role can no longer access even the MFA exception.
        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->get('/admin/second-factor')->assertForbidden();
        $this->getJson('/api/admin/second-factor/status')->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin', 'account_status' => 'suspended',
        ]);
        $this->get('/admin/second-factor')->assertForbidden();
        $this->getJson('/api/admin/second-factor/status')->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active', 'auth_epoch' => 1,
        ]);
        $this->get('/admin/second-factor')->assertStatus(409);
        $this->getJson('/api/admin/second-factor/status')->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0, 'password' => Hash::make('changed-admin-password'),
        ]);
        $this->get('/admin/second-factor')->assertStatus(409);
        $this->getJson('/api/admin/second-factor/status')->assertStatus(409);
        $this->assertDatabaseCount('admin_second_factors', 0);
        $this->assertDatabaseCount('audit_events', 0);

        $fresh = User::factory()->create(['role' => 'admin']);
        $this->actingAs($fresh, 'web')->getJson('/api/admin/second-factor/status')
            ->assertOk()->assertJsonPath('authenticator_required', true);
    }

    public function test_stale_admin_cannot_begin_or_confirm_authenticator_enrollment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin',
            'auth_epoch' => 1,
        ]);
        $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0,
            'password' => Hash::make('newly-rotated-password'),
        ]);
        $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertStatus(409);
        $this->assertDatabaseCount('admin_second_factors', 0);
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 0);

        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($otherAdmin, 'web');
        $secret = $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'password',
        ])->assertOk()->json('secret');
        DB::table('users')->where('id', $otherAdmin->id)->update([
            'account_status' => 'suspended',
        ]);
        $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertForbidden();
        $this->assertDatabaseCount('admin_second_factors', 0);
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 0);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'admin.second_factor.enrolled')->count());
    }

    public function test_stale_admin_cannot_consume_totp_or_recovery_code_or_rotate_backup_codes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');
        $secret = $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'password',
        ])->assertOk()->json('secret');
        $codes = $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->travel(35)->seconds();

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 1,
        ]);
        $this->postJson('/api/admin/second-factor/verify', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertStatus(409);
        $this->postJson('/api/admin/second-factor/recover', [
            'recovery_code' => $codes[0],
        ])->assertStatus(409);
        $this->postJson('/api/admin/second-factor/rotate-recovery-codes', [
            'password' => 'password',
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertStatus(409);
        $this->assertSame(0, DB::table('admin_second_factor_recovery_codes')
            ->whereNotNull('used_at')->count());
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 8);
        $this->assertSame(0, DB::table('audit_events')
            ->whereIn('action', [
                'admin.second_factor.verified',
                'admin.second_factor.recovered',
                'admin.second_factor.recovery_codes_rotated',
            ])->count());
    }

    public function test_stale_admin_cannot_reset_authenticator_after_valid_recovery(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'password',
        ])->assertOk()->json('secret');
        $codes = $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk()->json('recovery_codes');
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk();
        $this->postJson('/api/admin/second-factor/recover', [
            'recovery_code' => $codes[0],
        ])->assertOk();
        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'vendor',
        ]);
        $this->postJson('/api/admin/second-factor/reset-after-recovery', [
            'password' => 'password',
        ])->assertForbidden();
        $this->assertDatabaseCount('admin_second_factors', 1);
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 8);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'admin.second_factor.reset_for_reenrollment')->count());
    }
}
