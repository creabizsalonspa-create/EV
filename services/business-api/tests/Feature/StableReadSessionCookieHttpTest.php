<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StableReadSessionCookieHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // JSON feature requests omit cookies unless the browser contract opts in.
        $this->withCredentials();
    }

    private function sessionCookies($response): array
    {
        return array_values(array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === config('session.cookie')));
    }

    public function test_actual_cookie_pipeline_issues_bootstrap_but_does_not_repeat_stable_read_cookie(): void
    {
        $bootstrap = $this->getJson('/api/csrf')->assertOk();
        $this->assertCount(1, $this->sessionCookies($bootstrap));
        $id = session()->getId();
        $this->withCookie(config('session.cookie'), $id);
        $reply = $this->getJson('/api/csrf')->assertOk();
        $this->assertCount(0, $this->sessionCookies($reply));
        $this->assertSame($id, session()->getId());
        $this->assertNotEmpty($reply->json('token'));
    }

    public function test_login_and_logout_still_rotate_and_issue_session_cookies_and_scoped_identity_is_retained(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Synthetic-strong-password-123!')]);
        $this->getJson('/api/csrf')->assertOk();
        $before = session()->getId();
        $this->withCookie(config('session.cookie'), $before);
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Synthetic-strong-password-123!'])->assertOk();
        $this->assertCount(1, $this->sessionCookies($login));
        $current = session()->getId();
        $this->assertNotSame($before, $current);
        $this->withCookie(config('session.cookie'), $current);
        $me = $this->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->assertCount(0, $this->sessionCookies($me));
        $logout = $this->postJson('/api/logout')->assertSuccessful();
        $this->assertCount(1, $this->sessionCookies($logout));
        $this->assertNotSame($current, session()->getId());
    }

    public function test_explicit_get_session_rotation_is_not_suppressed(): void
    {
        Route::middleware('web')->get('/testing/session-rotation', function (Request $request) {
            $request->session()->migrate(true);

            return response()->json(['rotated' => true]);
        });
        $this->getJson('/api/csrf')->assertOk();
        $before = session()->getId();
        $this->withCookie(config('session.cookie'), $before);
        $reply = $this->getJson('/testing/session-rotation')->assertOk();
        $this->assertCount(1, $this->sessionCookies($reply));
        $this->assertNotSame($before, session()->getId());
    }
}
