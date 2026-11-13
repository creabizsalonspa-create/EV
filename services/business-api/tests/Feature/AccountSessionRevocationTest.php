<?php

namespace Tests\Feature;

use App\Http\Controllers\MobileAuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_vendor_can_revoke_only_their_own_credentials(): void
    {
        foreach (['customer', 'vendor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $other = User::factory()->create();
            $token = $user->createToken('mobile')->accessToken->id;
            $otherToken = $other->createToken('mobile')->accessToken->id;
            foreach ([$user, $other] as $account) {
                DB::table('sessions')->insert(['id' => 'session-'.$account->id, 'user_id' => $account->id, 'payload' => 'test', 'last_activity' => now()->timestamp]);
            }
            $this->actingAs($user, 'web')->withSession(['account_auth_epoch' => 0])
                ->postJson('/api/account/sessions/revoke-all', ['password' => 'password', 'user_id' => $other->id])
                ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
            $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token]);
            $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken]);
            $this->assertDatabaseMissing('sessions', ['id' => 'session-'.$user->id]);
            $this->assertDatabaseHas('sessions', ['id' => 'session-'.$other->id]);
            $this->assertSame(1, $user->fresh()->auth_epoch);
            $this->assertSame(0, $other->fresh()->auth_epoch);
            $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'action' => 'account.sessions.revoked_all']);
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/me')->assertUnauthorized();
        }
    }

    public function test_password_confirmation_is_required_without_side_effects(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->accessToken->id;
        $this->actingAs($user);
        foreach (['', 'incorrect', str_repeat('x', 129)] as $password) {
            $this->postJson('/api/account/sessions/revoke-all', ['password' => $password])->assertUnprocessable();
        }
        $this->assertSame(0, $user->fresh()->auth_epoch);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_native_token_revocation_invalidates_all_tokens_and_old_browser_epoch(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['platform'], now()->addDay())->plainTextToken;
        $old = $user->createToken('other device', ['platform'], now()->addDay())->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/account/sessions/revoke-all', ['password' => 'password'])->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->actingAs($user->fresh(), 'web')->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_guests_admin_and_suspended_accounts_cannot_use_customer_revocation(): void
    {
        $this->postJson('/api/account/sessions/revoke-all', ['password' => 'password'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/account/sessions/revoke-all', ['password' => 'password'])->assertForbidden();
        $this->actingAs(User::factory()->create(['account_status' => 'suspended']))
            ->postJson('/api/account/sessions/revoke-all', ['password' => 'password'])->assertForbidden();
    }

    public function test_inflight_mobile_login_cannot_issue_after_revocation_or_password_change(): void
    {
        $stale = User::factory()->create();
        $stale->fresh()->forceFill(['auth_epoch' => 1])->save();
        $method = new \ReflectionMethod(MobileAuthController::class, 'issue');
        try {
            $method->invoke(new MobileAuthController, $stale);
            $this->fail('A stale sign-in must not create a token.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
