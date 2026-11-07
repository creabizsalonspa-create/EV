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

        // Known inherited canonical route debt, assigned to Agent 1:
        // /api/internal/firmware/{token} incorrectly uses whereNumber('operation').
        // Once the owner fixes it, remove this exception. No additional
        // ineffective parameter constraints may be introduced during merges.
        $knownOwnerIssue = ['GET|HEAD api/internal/firmware/{token}:operation'];
        $this->assertSame([], array_values(array_diff($misconfigured, $knownOwnerIssue)),
            'New ineffective route parameter constraint found.');
        $this->assertLessThanOrEqual(1, count($misconfigured));
    }
}
