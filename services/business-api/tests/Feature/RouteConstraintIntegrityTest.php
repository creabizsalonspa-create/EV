<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A where() policy attached to a nonexistent URI parameter does not
 * constrain requests. Audit the fully bootstrapped route tree, including
 * routes imported from separate internal and finance files.
 */
class RouteConstraintIntegrityTest extends TestCase
{
    public function test_route_parameter_constraints_refer_to_real_parameters(): void
    {
        $misconfigured = [];
        $examined = 0;
        foreach (Route::getRoutes() as $route) {
            $examined++;
            $parameters = $route->parameterNames();

            foreach ($route->wheres as $parameter => $pattern) {
                if (! in_array($parameter, $parameters, true)) {
                    $misconfigured[] = implode('|', $route->methods()).' '.$route->uri().':'.$parameter;
                }
            }
        }

        $this->assertGreaterThan(500, $examined);

        // No inherited exceptions: a misnamed where() policy silently
        // exposes the unconstrained route and must fail this CI inventory.
        $this->assertSame([], $misconfigured,
            'Every route parameter constraint must refer to a real URI parameter.');
    }
}
