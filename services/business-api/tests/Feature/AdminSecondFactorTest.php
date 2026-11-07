<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\AdminTotp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSecondFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('admin_security.enforce_in_local_tests', true);
    }

    public function test_rfc_6238_vector_six_digits_and_step_replay_rejection(): void
    {
        // RFC 6238 SHA1 shared secret "12345678901234567890".
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', AdminTotp::codeAtStep($secret, 1));
        $this->assertSame(1, AdminTotp::matchingStep($secret, '287082', 59));
        $this->assertNull(AdminTotp::matchingStep($secret, '287082', 59, 1));
        $this->assertNull(AdminTotp::matchingStep($secret, '0287082', 59));
        $this->assertSame(32, strlen(AdminTotp::generateSecret()));
    }

    public function test_admin_login_requires_enrollment_and_challenge_before_protected_apis(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('admin_second_factor_required', true);

        $this->getJson('/api/me')->assertOk();
        $this->getJson('/api/admin/overview')->assertForbidden()
            ->assertJsonPath('admin_second_factor_required', true);
        $this->getJson('/api/vendor-ops/companies')->assertForbidden();
        $this->get('/')->assertRedirect('/admin/second-factor');
        $this->get('/admin/second-factor')->assertOk();

        $this->postJson('/api/admin/second-factor/verify', ['code' => '000000'])->assertStatus(409);
        $this->postJson('/api/admin/second-factor/begin', ['password' => 'wrong'])->assertUnprocessable();
        $response = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->assertJsonPath('expires_in_seconds', 600);
        $secret = $response->json('secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringContainsString('otpauth://totp/', $response->json('provisioning_uri'));
        $this->assertDatabaseCount('admin_second_factors', 0);

        $code = AdminTotp::codeAtStep($secret, intdiv(time(), 30));
        $this->postJson('/api/admin/second-factor/confirm', ['code' => $code])
            ->assertOk()->assertJsonPath('verified', true)->assertJsonMissingPath('secret');
        $this->getJson('/api/admin/second-factor/status')->assertOk()
            ->assertJsonPath('enrolled', true)->assertJsonPath('verified', true)
            ->assertJsonMissingPath('secret');
        $this->getJson('/api/admin/overview')->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'admin.second_factor.enrolled')->count());
        $row = DB::table('admin_second_factors')->first();
        $this->assertNotSame($secret, $row->encrypted_secret);
        $this->assertStringNotContainsString($secret, json_encode($row));
    }

    public function test_new_login_requires_fresh_single_use_totp_and_rejects_replay(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $code = AdminTotp::codeAtStep($secret, intdiv(time(), 30));
        $this->postJson('/api/admin/second-factor/confirm', ['code' => $code])->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->postJson('/api/admin/second-factor/verify', ['code' => $code])->assertUnprocessable();

        $this->travel(35)->seconds();
        $newCode = AdminTotp::codeAtStep($secret, intdiv(time(), 30));
        $this->postJson('/api/admin/second-factor/verify', ['code' => $newCode])->assertOk();
        $this->getJson('/api/admin/overview')->assertOk();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'admin.second_factor.verified')->count());
    }

    public function test_enrollment_expires_and_non_admins_cannot_see_authenticator_data(): void
    {
        $this->getJson('/api/admin/second-factor/status')->assertUnauthorized();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->getJson('/api/admin/second-factor/status')->assertForbidden();
        $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $this->travel(11)->minutes();
        $code = AdminTotp::codeAtStep($secret, intdiv(time(), 30));
        $this->postJson('/api/admin/second-factor/confirm', ['code' => $code])->assertConflict();
        $this->assertDatabaseCount('admin_second_factors', 0);
    }

    public function test_customer_and_vendor_web_login_are_not_interrupted_by_admin_challenge(): void
    {
        foreach (['customer', 'vendor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
                ->assertOk()->assertJsonPath('admin_second_factor_required', false);
            $this->getJson('/api/me')->assertOk()->assertJsonPath('role', $role);
            $this->get('/')->assertOk();
            $this->postJson('/api/logout')->assertNoContent();
        }
    }
}
