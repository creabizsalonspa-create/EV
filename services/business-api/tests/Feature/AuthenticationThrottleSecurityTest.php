<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Attackers must not brute-force login or enumerate password recovery faster
 * than the actual configured per-IP budgets. Uses synthetic unroutable IPs.
 */
class AuthenticationThrottleSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rate_limit_is_enforced_before_excessive_authentication_attempts(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.41']);

        $body = [
            'email' => 'unknown-qa-rate-limit@example.test',
            'password' => 'test-only-unknown-password',
        ];

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/login', $body)->assertUnprocessable();
        }

        $limited = $this->postJson('/api/login', $body);
        $limited->assertStatus(429);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $limited->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_password_recovery_has_separate_lower_budget_without_account_enumeration(): void
    {
        Notification::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.42']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/forgot-password', [
                'email' => 'unknown-qa-recovery@example.test',
            ])->assertAccepted()->assertExactJson([
                'message' => 'If eligible, a password reset link has been sent.',
            ]);
        }

        $limited = $this->postJson('/api/forgot-password', [
            'email' => 'unknown-qa-recovery@example.test',
        ]);
        $limited->assertStatus(429);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));

        // A different address is not penalized by an unrelated caller's budget.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.43']);
        $this->postJson('/api/forgot-password', [
            'email' => 'unknown-qa-recovery@example.test',
        ])->assertAccepted();
    }

    public function test_web_and_native_login_share_one_per_ip_attempt_budget(): void
    {
        // An attacker must not receive a second fresh budget merely by
        // changing the HTTP client from browser to mobile app.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44']);
        $credentials = [
            'email' => 'unknown-qa-cross-client@example.test',
            'password' => 'test-only-unknown-password',
        ];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson('/api/login', $credentials)->assertUnprocessable();
        }

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/login', $credentials)->assertUnprocessable();
        }

        $this->postJson('/api/v1/login', $credentials)
            ->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');

        // Exhausting one source IP must not prevent another IP from
        // attempting a legitimate login.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.45']);
        $this->postJson('/api/v1/login', $credentials)->assertUnprocessable();
    }

}
