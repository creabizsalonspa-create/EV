<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
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
            $http = $this->get($path);
            $http->assertOk();
            $this->assertFalse($http->headers->has('Strict-Transport-Security'));

            $https = $this->withServerVariables(['HTTPS' => 'on'])->get($path);
            $https->assertOk();
            $this->assertSame('max-age=31536000', $https->headers->get('Strict-Transport-Security'));
        }
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
