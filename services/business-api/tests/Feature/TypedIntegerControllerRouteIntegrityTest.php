<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Identify dangerous integer route parameters by reflecting the *registered*
 * Laravel controller actions, including imported and dynamically built routes.
 *
 * The source-only whereNumber inventory cannot detect a missing constraint;
 * this test also catches routes where there is no where() call whatsoever.
 */
class TypedIntegerControllerRouteIntegrityTest extends TestCase
{
    public function test_all_registered_controller_int_path_parameters_are_bounded_before_dispatch(): void
    {
        $unbounded = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                // Closures and invokables without named controller methods
                // cannot be mapped to a method and are not covered here.
                continue;
            }

            [$class, $method] = explode('@', $action, 2);
            if (! str_starts_with($class, 'App\\Http\\Controllers\\') || ! method_exists($class, $method)) {
                continue;
            }

            $signature = new ReflectionMethod($class, $method);
            $uriParameters = $route->parameterNames();
            foreach ($signature->getParameters() as $argument) {
                $type = $argument->getType();
                if (! $type instanceof ReflectionNamedType || $type->getName() !== 'int') {
                    // Eloquent model bindings and validated string parameters
                    // have separate validation semantics.
                    continue;
                }

                $parameter = $argument->getName();
                if (! in_array($parameter, $uriParameters, true)) {
                    continue;
                }

                $checked++;
                if (($route->wheres[$parameter] ?? null) !== '[0-9]{1,18}') {
                    $unbounded[] = implode('|', $route->methods()).' '.$route->uri()
                        .' -> '.$class.'@'.$method.' ($'.$parameter.')';
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'The registered controller integer route audit must not be vacuous.');
        $this->assertSame([], $unbounded,
            'Every controller int path parameter must have a bounded numeric route constraint.');
    }
}
