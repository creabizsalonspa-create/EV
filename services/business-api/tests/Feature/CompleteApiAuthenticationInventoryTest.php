<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Gate the entire registered web and native API attack surface, not only
 * selected URL prefixes. A new unauthenticated endpoint requires human review.
 * Listed callbacks use separate signed/source-auth mechanisms, not public
 * merchant or customer authentication.
 */
class CompleteApiAuthenticationInventoryTest extends TestCase
{
    private const EXPLICIT_PUBLIC_API_SURFACE = [
        'DELETE|GET|HEAD|POST|PUT api/roaming-protocol/2.2.1/credentials',
        'GET|HEAD api/csrf',
        'GET|HEAD api/discovery/capabilities',
        'GET|HEAD api/discovery/open-charge-map/capabilities',
        'GET|HEAD api/internal/firmware/{token}',
        'GET|HEAD api/regions',
        'GET|HEAD api/roaming-protocol/2.2.1',
        'GET|HEAD api/roaming-protocol/2.2.1/cdrs/{external}',
        'GET|HEAD api/roaming-protocol/2.2.1/tokens',
        'GET|HEAD api/roaming-protocol/2.2.1/tokens/{country}/{party}/{uid}',
        'GET|HEAD api/roaming-protocol/versions',
        'GET|HEAD api/roaming/locations',
        'GET|HEAD api/stations',
        'GET|HEAD api/stations/{station}/connectors',
        'GET|HEAD api/stations/{station}/reviews',
        'GET|HEAD api/stations/{station}/information',
        'GET|HEAD api/v1/discovery/capabilities',
        'GET|HEAD api/v1/discovery/open-charge-map/capabilities',
        'GET|HEAD api/v1/regions',
        'GET|HEAD api/v1/stations',
        'GET|HEAD api/v1/stations/{station}/connectors',
        'GET|HEAD api/v1/stations/{station}/reviews',
        'GET|HEAD api/v1/stations/{station}/information',
        'GET|HEAD|PATCH|PUT api/roaming-protocol/2.2.1/sessions/{country}/{party}/{external}',
        'POST api/corporate-payments/stripe-test/webhook',
        'POST api/discovery/addresses',
        'POST api/discovery/open-charge-map/search',
        'POST api/discovery/route',
        'POST api/disputes/stripe-test/webhook',
        'POST api/forgot-password',
        'POST api/internal/charger-ingress/authenticate',
        'POST api/internal/commercial/campaign-deliveries',
        'POST api/internal/csms/events',
        'POST api/internal/remote-session-authorize',
        'POST api/internal/rider-authorize',
        'POST api/internal/rider-oauth/token',
        'POST api/login',
        'POST api/payment-enrollments/stripe-test/webhook',
        'POST api/payments/mock/webhook',
        'POST api/payments/stripe-test/webhook',
        'POST api/register',
        'POST api/reset-password',
        'POST api/roaming-protocol/2.2.1/cdrs',
        'POST api/roaming-protocol/2.2.1/commands/{key}',
        'POST api/roaming-protocol/2.2.1/tokens/{uid}/authorize',
        'POST api/subscription-credit-payments/stripe-test/webhook',
        'POST api/subscription-payments/stripe-test/webhook',
        'POST api/v1/discovery/addresses',
        'POST api/v1/discovery/open-charge-map/search',
        'POST api/v1/discovery/route',
        'POST api/v1/login',
        'POST api/v1/register',
        'POST api/webhooks/payouts/stripe-test',
        'PUT api/internal/diagnostic-logs/{operation}',
    ];

    public function test_every_api_route_is_authenticated_or_in_reviewed_public_inventory(): void
    {
        $exposed = [];
        $total = 0;
        $private = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $total++;
            $methods = $route->methods();
            sort($methods, SORT_STRING);
            $identity = implode('|', $methods).' '.$route->uri();
            $middleware = $route->gatherMiddleware();
            $authenticated = collect($middleware)->contains(fn ($m) => is_string($m)
                && ($m === 'auth' || str_starts_with($m, 'auth:')));
            if ($authenticated) {
                $private++;

                continue;
            }

            $exposed[] = $identity;
            if (array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                $this->assertTrue(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')),
                    'Public API mutation must be throttled: '.$identity);
            }
        }

        $expected = self::EXPLICIT_PUBLIC_API_SURFACE;
        sort($expected, SORT_STRING);
        sort($exposed, SORT_STRING);

        $this->assertGreaterThan(600, $total);
        $this->assertGreaterThan(500, $private);
        $this->assertCount(count(array_unique($exposed)), $exposed, 'Duplicate public API route identity found.');
        $this->assertSame($expected, $exposed,
            'A new or changed public API endpoint requires explicit authentication/security review.');
    }
}
