<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMobileTokenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_mobile_token_loses_all_protected_access_after_admin_promotion(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $token = $vendor->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $vendor->forceFill(['role' => 'admin'])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/vendor-ops/companies')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/finance/invoices')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/notifications')->assertForbidden();

        // A now-forbidden token can still revoke itself without privileges.
        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_login_and_existing_admin_bearer_are_denied_even_when_token_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/api/v1/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertForbidden();

        $token = $admin->createToken('legacy', ['platform'], now()->addDay())->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_customer_and_vendor_tokens_remain_valid_and_admin_web_session_stays_separate(): void
    {
        foreach (['customer', 'vendor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $token = $user->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')
                ->assertOk()->assertJsonPath('role', $role);
        }

        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withHeaders(['Authorization' => ''])->actingAs($admin)
            ->getJson('/api/admin/overview')->assertOk();
    }
}
