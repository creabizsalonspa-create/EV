<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PrivateRouteAuthenticationInventoryTest extends TestCase
{
    /** Only these native routes intentionally admit unauthenticated callers. */
    private const PUBLIC_NATIVE_ROUTES = [
        'api/v1/login',
        'api/v1/register',
        'api/v1/regions',
        'api/v1/stations',
        'api/v1/stations/{station}/connectors',
        'api/v1/stations/{station}/reviews',
        'api/v1/stations/{station}/information',
        'api/v1/discovery/addresses',
        'api/v1/discovery/capabilities',
        'api/v1/discovery/open-charge-map/capabilities',
        'api/v1/discovery/open-charge-map/search',
        'api/v1/discovery/route',
    ];

    public function test_every_private_native_and_sensitive_web_route_has_authentication(): void
    {
        $sensitive = [
            'api/admin/',
            'api/vendor-ops/',
            'api/finance/',
            'api/charger-commands',
            'api/charging-sessions/',
            'api/reservations',
            'api/notifications',
            'api/support',
            'api/v1/',
        ];

        $checked = 0;
        $public = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! collect($sensitive)->contains(fn (string $prefix) => str_starts_with($uri, $prefix))) {
                continue;
            }

            $methods = $route->methods();
            // Laravel often includes HEAD on GET routes; authentication must
            // hold for every advertised method, including non-GET commands.
            $this->assertNotEmpty($methods, 'Missing methods for '.$uri);
            $middleware = $route->gatherMiddleware();
            $authenticated = collect($middleware)->contains(fn ($name) => is_string($name)
                && ($name === 'auth' || str_starts_with($name, 'auth:')));
            if (! $authenticated && str_starts_with($uri, 'api/v1/')) {
                $this->assertContains($uri, self::PUBLIC_NATIVE_ROUTES,
                    'New public native endpoint needs explicit privacy/QA review: '.$uri);
                $public[$uri] = true;
            } else {
                $this->assertTrue($authenticated, 'Missing authentication on private API route '.$uri);
                $checked++;
            }
        }

        $this->assertGreaterThan(250, $checked, 'Expected a nontrivial private API inventory.');
        $expected = self::PUBLIC_NATIVE_ROUTES;
        sort($expected);
        $actual = array_keys($public);
        sort($actual);
        $this->assertSame($expected, $actual,
            'A native public route was removed or added; audit its authorization assumptions.');
    }
}
