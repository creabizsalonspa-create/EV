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

        $code = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
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

    public function test_failed_enrollment_totp_and_recovery_attempts_are_audited_without_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk();
        $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'incorrect-private-password',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.enrollment_password_denied',
            'subject' => (string) $admin->id,
        ]);

        $secret = $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'password',
        ])->assertOk()->json('secret');
        $invalid = collect(['000000', '111111', '222222', '333333', '444444'])
            ->first(fn ($candidate) => AdminTotp::matchingStep($secret, $candidate, now()->timestamp) === null);
        $this->assertNotNull($invalid);
        $this->postJson('/api/admin/second-factor/confirm', [
            'code' => $invalid,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.enrollment_code_denied',
        ]);
        $this->assertDatabaseCount('admin_second_factors', 0);

        $valid = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
        $this->postJson('/api/admin/second-factor/confirm', [
            'code' => $valid,
        ])->assertOk();
        $acceptedStep = DB::table('admin_second_factors')
            ->where('user_id', $admin->id)->value('last_accepted_step');

        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk();
        $this->postJson('/api/admin/second-factor/verify', [
            'code' => $valid,
        ])->assertUnprocessable();
        $this->postJson('/api/admin/second-factor/recover', [
            'recovery_code' => str_repeat('0', 20),
        ])->assertUnprocessable();
        $this->assertSame($acceptedStep,
            DB::table('admin_second_factors')
                ->where('user_id', $admin->id)->value('last_accepted_step'));

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.verify_denied',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.recovery_denied',
        ]);
        $audit = DB::table('audit_events')->get()->toJson();
        $this->assertStringNotContainsString($secret, $audit);
        $this->assertStringNotContainsString('incorrect-private-password', $audit);
        $this->assertStringNotContainsString(str_repeat('0', 20), $audit);
        $this->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_failed_recovery_reset_password_is_audited_without_changing_factor(): void
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
        $this->postJson('/api/admin/second-factor/reset-after-recovery', [
            'password' => 'bad-password',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.recovery_reset_password_denied',
        ]);
        $this->assertDatabaseCount('admin_second_factors', 1);
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->assertStringNotContainsString($codes[0],
            DB::table('audit_events')->get()->toJson());
    }

    public function test_recovery_code_rotation_requires_verified_mfa_fresh_password_and_unused_totp(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $route = '/api/admin/second-factor/rotate-recovery-codes';
        $this->postJson($route, ['password' => 'password', 'code' => '000000'])
            ->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk();
        // Correct password but no enrolled/verifiable MFA is insufficient.
        $this->postJson($route, ['password' => 'password', 'code' => '000000'])
            ->assertForbidden();
        $secret = $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'password',
        ])->assertOk()->json('secret');
        $oldCodes = $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $oldCodes);
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 8);

        // Enrollment used the current 30-second step. A new step is needed
        // before reissuing backup codes; the same TOTP cannot be replayed.
        $this->postJson($route, [
            'password' => 'password',
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->travel(35)->seconds();
        $nextCode = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
        $this->postJson($route, [
            'password' => 'incorrect-admin-password',
            'code' => $nextCode,
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertDatabaseHas('admin_second_factor_recovery_codes', [
            'code_digest' => hash('sha256', $oldCodes[0]),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.recovery_rotation_denied',
        ]);

        $result = $this->postJson($route, [
            'password' => 'password',
            'code' => $nextCode,
        ])->assertOk()->assertJsonPath('old_codes_invalidated', true)
            ->assertJsonPath('authenticator_changed', false)
            ->assertJsonCount(8, 'recovery_codes')
            ->assertHeader('Cache-Control', 'no-store, private');
        $newCodes = $result->json('recovery_codes');
        $this->assertCount(8, array_unique($newCodes));
        $this->assertNotContains($oldCodes[0], $newCodes);
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 8);
        $this->assertDatabaseMissing('admin_second_factor_recovery_codes', [
            'code_digest' => hash('sha256', $oldCodes[0]),
        ]);
        $this->assertDatabaseHas('admin_second_factor_recovery_codes', [
            'code_digest' => hash('sha256', $newCodes[0]),
        ]);
        $this->assertDatabaseCount('admin_second_factors', 1);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.recovery_codes_rotated',
        ]);
        $audit = DB::table('audit_events')->get()->toJson();
        $this->assertStringNotContainsString($nextCode, $audit);
        $this->assertStringNotContainsString($newCodes[0], $audit);
        $this->assertStringNotContainsString('incorrect-admin-password', $audit);

        $this->postJson($route, ['password' => 'password', 'code' => $nextCode])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 8);

        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk();
        $this->postJson('/api/admin/second-factor/recover', [
            'recovery_code' => $oldCodes[0],
        ])->assertUnprocessable();
        $this->postJson('/api/admin/second-factor/recover', [
            'recovery_code' => $newCodes[0],
        ])->assertOk()->assertJsonPath('verified', false);
        $this->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_recovery_code_rotation_is_rejected_when_verified_grant_expires(): void
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
        $this->travel(13)->hours();

        $this->postJson('/api/admin/second-factor/rotate-recovery-codes', [
            'password' => 'password',
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertForbidden();
        $this->assertDatabaseHas('admin_second_factor_recovery_codes', [
            'code_digest' => hash('sha256', $codes[0]),
        ]);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'admin.second_factor.recovery_codes_rotated')->count());
    }

    public function test_new_login_requires_fresh_single_use_totp_and_rejects_replay(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $code = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
        $this->postJson('/api/admin/second-factor/confirm', ['code' => $code])->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->postJson('/api/admin/second-factor/verify', ['code' => $code])->assertUnprocessable();

        $this->travel(35)->seconds();
        $newCode = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
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
        $code = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
        $this->postJson('/api/admin/second-factor/confirm', ['code' => $code])->assertConflict();
        $this->assertDatabaseCount('admin_second_factors', 0);
    }

    public function test_recovery_codes_are_single_use_private_and_require_password_to_replace_device(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $confirm = $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk();
        $codes = $confirm->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 8);
        $this->assertDatabaseMissing('admin_second_factor_recovery_codes', ['code_digest' => $codes[0]]);
        $this->getJson('/api/admin/second-factor/status')->assertJsonMissingPath('recovery_codes');

        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/admin/second-factor/recover', ['recovery_code' => str_repeat('0', 20)])
            ->assertUnprocessable();
        $this->postJson('/api/admin/second-factor/recover', ['recovery_code' => $codes[0]])
            ->assertOk()->assertJsonPath('verified', false)->assertJsonPath('recovery_reset_available', true);
        $this->getJson('/api/admin/second-factor/status')
            ->assertJsonPath('verified', false)->assertJsonPath('recovery_reset_available', true);
        // Critical boundary: a recovered session can replace its factor, but
        // cannot access privileged Admin APIs before a fresh TOTP challenge.
        $this->getJson('/api/admin/overview')->assertForbidden()
            ->assertJsonPath('admin_second_factor_required', true);
        $this->getJson('/api/vendor-ops/companies')->assertForbidden();
        $this->get('/')->assertRedirect('/admin/second-factor');
        $this->postJson('/api/admin/second-factor/recover', ['recovery_code' => $codes[0]])
            ->assertUnprocessable();
        $this->assertSame(1, DB::table('admin_second_factor_recovery_codes')
            ->whereNotNull('used_at')->count());
        $this->postJson('/api/admin/second-factor/reset-after-recovery', ['password' => 'wrong'])
            ->assertUnprocessable();
        $this->postJson('/api/admin/second-factor/reset-after-recovery', ['password' => 'password'])
            ->assertOk()->assertJsonPath('enrolled', false);
        $this->assertDatabaseCount('admin_second_factors', 0);
        $this->assertDatabaseCount('admin_second_factor_recovery_codes', 0);
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])->assertOk();
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.second_factor.reset_for_reenrollment',
        ]);
    }

    public function test_recovery_only_authorization_expires_and_cannot_be_renewed_without_an_unused_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $codes = $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk()->json('recovery_codes');
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/admin/second-factor/recover', ['recovery_code' => $codes[0]])
            ->assertOk()->assertJsonPath('verified', false);
        $this->travel(11)->minutes();
        $this->getJson('/api/admin/second-factor/status')
            ->assertJsonPath('recovery_reset_available', false)
            ->assertJsonPath('verified', false);
        $this->postJson('/api/admin/second-factor/reset-after-recovery', ['password' => 'password'])
            ->assertForbidden();
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->assertDatabaseCount('admin_second_factors', 1);
        $this->postJson('/api/admin/second-factor/recover', ['recovery_code' => $codes[0]])
            ->assertUnprocessable();
    }

    public function test_fresh_password_login_discards_recovery_only_authorization(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $codes = $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk()->json('recovery_codes');
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/admin/second-factor/recover', ['recovery_code' => $codes[0]])
            ->assertOk();
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/admin/second-factor/status')
            ->assertJsonPath('verified', false)
            ->assertJsonPath('recovery_reset_available', false);
        $this->postJson('/api/admin/second-factor/reset-after-recovery', ['password' => 'password'])
            ->assertForbidden();
    }

    public function test_password_alone_cannot_reset_enrolled_factor_without_used_recovery_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk();
        $this->postJson('/api/admin/second-factor/reset-after-recovery', ['password' => 'password'])
            ->assertForbidden();
        $this->assertDatabaseCount('admin_second_factors', 1);
    }

    public function test_admin_factor_grant_expires_after_twelve_hours_and_requires_new_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $secret = $this->postJson('/api/admin/second-factor/begin', ['password' => 'password'])
            ->assertOk()->json('secret');
        $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk();
        $this->getJson('/api/admin/overview')->assertOk();

        $this->travel(13)->hours();
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->getJson('/api/admin/second-factor/status')->assertOk()
            ->assertJsonPath('verified', false);

        $this->postJson('/api/admin/second-factor/verify', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk();
        $this->getJson('/api/admin/overview')->assertOk();
    }

    public function test_admin_native_token_and_unverified_web_session_cannot_bypass_the_api_group_gate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('legacy-mobile', ['platform'], now()->addDay())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()
            ->assertJsonPath('admin_second_factor_required', true);

        $this->app['auth']->forgetGuards();
        $customer = User::factory()->create(['role' => 'customer']);
        $mobileToken = $customer->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
        $this->withToken($mobileToken)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('role', 'customer');
    }

    public function test_legacy_admin_bearer_can_revoke_itself_without_bypassing_mandatory_mfa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('legacy-mobile', ['platform'], now()->addDay())->plainTextToken;

        // A legacy bearer cannot read account data or gain browser Admin authority.
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()
            ->assertJsonPath('admin_second_factor_required', true);

        // Logout is the single allowed native action: Sanctum authenticates,
        // then the logout handler destroys the exact access token.
        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/logout')->assertUnauthorized();
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
