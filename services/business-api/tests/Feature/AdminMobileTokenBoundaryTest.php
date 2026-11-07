<?php

namespace Tests\Feature;

use App\Models\User;
use App\Http\Middleware\RejectAdminPersonalAccessToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMobileTokenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_sanctum_protected_native_route_has_the_mobile_admin_boundary(): void
    {
        $protected = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            $protected++;
            $this->assertContains(RejectAdminPersonalAccessToken::class, $middleware, $route->uri().' must not permit Admin bearer tokens.');
            $this->assertLessThan(
                array_search(RejectAdminPersonalAccessToken::class, $middleware, true),
                array_search('auth:sanctum', $middleware, true),
                'Admin bearer guard must run after Sanctum authentication.'
            );
        }

        $this->assertGreaterThan(0, $protected, 'Expected authenticated native routes.');
    }

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
