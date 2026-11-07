<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_blank_route_content_security_policy_cannot_disable_the_platform_default(): void
    {
        $middleware = new SecurityHeaders;

        foreach (['', '   '] as $blankPolicy) {
            $request = Request::create('/stations', 'GET');
            $result = $middleware->handle($request, function () use ($blankPolicy) {
                $response = new Response('safe');
                $response->headers->set('Content-Security-Policy', $blankPolicy);

                return $response;
            });

            $policy = $result->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("base-uri 'self'", $policy);
            $this->assertStringContainsString("object-src 'none'", $policy);
            $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        }
    }

    public function test_hosted_payment_return_keeps_the_route_specific_restrictive_csp(): void
    {
        $response = $this->get('/payments/hosted-return?hosted_reference=synthetic-reference');

        $response->assertOk();
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy);
        $this->assertStringContainsString("base-uri 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_corporate_hosted_payment_return_uses_the_same_strict_policy(): void
    {
        $response = $this->get('/payments/corporate-hosted-return?hosted_reference=synthetic-reference');

        $response->assertOk();
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy);
        $this->assertStringContainsString("base-uri 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_hosted_payment_returns_set_hsts_only_for_secure_requests(): void
    {
        foreach (['/payments/hosted-return', '/payments/corporate-hosted-return'] as $path) {
            $http = $this->get('http://localhost'.$path);
            $http->assertOk();
            $this->assertFalse($http->headers->has('Strict-Transport-Security'));

            $https = $this->get('https://localhost'.$path);
            $https->assertOk();
            $this->assertSame('max-age=31536000', $https->headers->get('Strict-Transport-Security'));
        }
    }

    public function test_anonymous_private_customer_vendor_and_admin_api_routes_fail_closed(): void
    {
        // These are protected endpoints, not a catalog. Do not turn an
        // unauthenticated error or redirect into a cacheable customer record.
        foreach ([
            '/api/me',
            '/api/vehicles',
            '/api/reservations',
            '/api/fleets',
            '/api/admin/overview',
            '/api/charger-commands',
            '/api/support',
        ] as $path) {
            $response = $this->getJson($path);

            $response->assertUnauthorized();
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), $path);
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $path);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
        }
    }

    public function test_password_reset_token_page_never_leaks_a_referrer_or_cacheable_response(): void
    {
        $response = $this->get('/reset-password/synthetic-unusable-token');

        $response->assertOk();
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_regular_pages_still_receive_the_platform_default_csp(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }
}
