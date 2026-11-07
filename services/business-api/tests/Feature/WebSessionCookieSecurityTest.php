<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Session cookie protections are independent of the SPA's local storage.
 * Verify actual Laravel responses, not just static configuration defaults.
 */
class WebSessionCookieSecurityTest extends TestCase
{
    public function test_https_csrf_bootstrap_protects_the_session_cookie_and_response(): void
    {
        config([
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
        ]);

        $response = $this->get('https://localhost/api/csrf')->assertOk();
        $this->assertIsString($response->json('token'));
        $this->assertGreaterThan(20, strlen($response->json('token')));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $cookie = collect($response->headers->getCookies())->first(
            fn ($row) => $row->getName() === config('session.cookie')
        );
        $this->assertNotNull($cookie, 'Actual HTTPS response must set the session cookie.');
        $this->assertTrue($cookie->isSecure(), 'Session cookie must not travel over HTTP.');
        $this->assertTrue($cookie->isHttpOnly(), 'Session identifier must not be script-readable.');
        $this->assertSame('lax', $cookie->getSameSite(), 'Cross-site cookie transmission must be limited.');
    }

    public function test_private_web_api_does_not_turn_anonymous_redirect_into_public_cached_data(): void
    {
        $response = $this->getJson('/api/me?role=admin&id=1234567');
        $response->assertUnauthorized();
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}
