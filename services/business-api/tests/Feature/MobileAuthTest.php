<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_tokens_authenticate_expire_and_revoke_without_role_escalation(): void
    {
        $r = $this->postJson('/api/v1/register', ['name' => 'Mobile driver', 'email' => 'mobile@example.test', 'password' => 'strong-mobile-password', 'role' => 'admin'])->assertCreated()->assertJsonPath('user.role', 'customer');
        $token = $r->json('token');
        $this->assertNotEmpty($token);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'mobile@example.test');
        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_native_login_is_denied_and_token_caps_are_enforced(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/v1/login', ['email' => $a->email, 'password' => 'password'])->assertForbidden();
        $u = User::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/login', ['email' => $u->email, 'password' => 'password'])->assertOk();
        }
        $this->assertSame(5, $u->tokens()->count());
    }

    public function test_expired_native_token_is_rejected(): void
    {
        $u = User::factory()->create();
        $token = $u->createToken('expired', ['platform'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_native_vehicle_tenant_isolation(): void
    {
        $u = User::factory()->create();
        $v = User::factory()->create();
        $token = $u->createToken('device')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/vehicles', ['name' => 'Native EV', 'connector_type' => 'CCS2'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($v->createToken('other')->plainTextToken)->getJson('/api/v1/vehicles')->assertJsonCount(0, 'data');
    }
}
