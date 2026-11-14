<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AccountSignInThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotating_ips_and_switching_web_native_cannot_evade_the_account_budget(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email' => 'customer@example.invalid']);
        for ($i = 1; $i <= 10; $i++) {
            $route = $i % 2 === 0 ? '/api/login' : '/api/v1/login';
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])
                ->postJson($route, ['email' => $i % 2 === 0 ? strtoupper($user->email) : $user->email, 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $reply = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertStringNotContainsString($user->email, $reply->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_unknown_accounts_have_the_same_budget_and_cannot_lock_other_identities(): void
    {
        $user = User::factory()->create(['email' => 'other@example.invalid']);
        for ($i = 1; $i <= 11; $i++) {
            $reply = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/api/login', ['email' => 'unknown@example.invalid', 'password' => 'wrong-password']);
            $i <= 10 ? $reply->assertUnprocessable() : $reply->assertTooManyRequests();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_cache_identity_is_bounded_keyed_and_case_normalized_without_raw_identifiers(): void
    {
        $limiter = RateLimiter::limiter('signin-account');
        $first = $limiter(Request::create('/api/login', 'POST', ['email' => ' Customer@Example.Invalid ']));
        $second = $limiter(Request::create('/api/v1/login', 'POST', ['email' => 'customer@example.invalid']));
        $this->assertSame($first->key, $second->key);
        $this->assertSame(10, $first->maxAttempts);
        $this->assertMatchesRegularExpression('/^signin:[0-9a-f]{64}$/D', $first->key);
        $this->assertStringNotContainsString('customer', $first->key);
        $this->assertNotSame($first->key, $limiter(Request::create('/api/login', 'POST', ['email' => 'different@example.invalid']))->key);
        $this->assertIsString($limiter(Request::create('/api/login', 'POST', ['email' => ['invalid']]))->key);
    }
}
