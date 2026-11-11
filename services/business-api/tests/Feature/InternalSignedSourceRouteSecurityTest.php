<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * A public signed firmware URL is a 64-character lowercase hex capability,
 * not an arbitrary string or a numeric operation ID. No device or HTTP I/O.
 */
class InternalSignedSourceRouteSecurityTest extends TestCase
{
    public function test_firmware_capability_and_diagnostic_operation_paths_are_strictly_matched(): void
    {
        foreach ([
            ['GET', '/api/internal/firmware/', str_repeat('a', 64)],
            ['PUT', '/api/internal/diagnostic-logs/', '12345'],
        ] as [$method, $prefix, $valid]) {
            $route = Route::getRoutes()->match(Request::create($prefix.$valid, $method));
            $this->assertNotNull($route->getActionName(), $method.' '.$prefix);
        }

        foreach ([
            ['GET', '/api/internal/firmware/', [
                str_repeat('a', 63),
                str_repeat('a', 65),
                str_repeat('g', 64),
                str_repeat('A', 64),
                str_repeat('9', 120),
                'not-a-capability',
            ]],
            ['PUT', '/api/internal/diagnostic-logs/', [
                'not-an-operation',
                '-1',
                str_repeat('9', 19),
                str_repeat('9', 120),
            ]],
        ] as [$method, $prefix, $invalids]) {
            foreach ($invalids as $bad) {
                try {
                    Route::getRoutes()->match(Request::create($prefix.$bad, $method));
                    $this->fail('Invalid internal capability route dispatched: '.$method.' '.$prefix.$bad);
                } catch (NotFoundHttpException) {
                    // Invalid token or integer is denied before controller logic.
                }
            }
        }
    }
}
