<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A public or authenticated API still needs a bounded request budget. Auth
 * cannot substitute for throttling when a credential is stolen or a browser
 * is compromised. No production or fixture data is accessed by this audit.
 */
class SensitiveRouteThrottleInventoryTest extends TestCase
{
    public function test_sensitive_api_routes_have_an_explicit_throttle_policy(): void
    {
        $prefixes = [
            'api/admin/',
            'api/vendor-ops/',
            'api/finance/',
            'api/charger-commands',
            'api/charging-sessions/',
            'api/reservations',
            'api/notifications',
            'api/support',
            'api/v1/',
            'api/internal/',
            'api/stations/',
        ];

        $examined = 0;
        $internal = 0;
        $mutations = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! collect($prefixes)->contains(fn (string $prefix) => str_starts_with($uri, $prefix))) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $limiters = array_values(array_filter($middleware, fn ($value) => is_string($value)
                && str_starts_with($value, 'throttle:')));

            $this->assertNotEmpty($limiters, 'Sensitive API route must be throttled: '.$uri);
            foreach ($limiters as $limiter) {
                $this->assertNotSame('throttle:', $limiter);
                $this->assertNotSame('throttle:none', $limiter);
                $this->assertNotSame('throttle:unlimited', $limiter);
            }

            if (str_starts_with($uri, 'api/internal/')) {
                $internal++;
            }

            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods())) {
                $mutations++;
            }

            $examined++;
        }

        $this->assertGreaterThan(300, $examined, 'Unexpectedly small API attack-surface inventory.');
        $this->assertGreaterThan(5, $internal, 'All internal control endpoints must be represented.');
        $this->assertGreaterThan(100, $mutations, 'All sensitive write routes must be represented.');
    }
}
