<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Route matching must reject oversized operational IDs before the controller
 * can run, even for protected charger/firmware/diagnostic command endpoints.
 * This test performs no HTTP calls to devices and does not need database data.
 */
class ChargerOperationRouteParameterSecurityTest extends TestCase
{
    public function test_oversized_charger_operation_ids_cannot_dispatch_under_either_api_mount(): void
    {
        $cases = [
            ['DELETE', '/companies/1/chargers/1/connectors/1/maintenance-windows/{id}'],
            ['POST', '/companies/1/chargers/1/configuration-reads/{id}/observe'],
            ['PATCH', '/companies/1/chargers/1/fault-alerts/{id}/acknowledge'],
            ['POST', '/companies/1/chargers/1/reset-operations/{id}/review'],
            ['DELETE', '/companies/1/firmware-releases/{id}'],
            ['POST', '/companies/1/chargers/1/firmware-operations/{id}/review'],
            ['POST', '/companies/1/chargers/1/firmware-operations/{id}/cancel'],
            ['GET', '/companies/1/chargers/1/repair-orders/{id}/history'],
            ['POST', '/companies/1/chargers/1/diagnostic-logs/{id}/observe'],
            ['GET', '/companies/1/chargers/1/diagnostic-logs/{id}/download'],
            ['DELETE', '/companies/1/chargers/1/diagnostic-logs/{id}'],
        ];

        foreach (['/api/vendor-ops', '/api/v1/vendor-ops'] as $prefix) {
            foreach ($cases as [$method, $fragment]) {
                $valid = str_replace('{id}', '12345', $prefix.$fragment);
                $route = Route::getRoutes()->match(Request::create($valid, $method));
                $this->assertNotNull($route->getActionName(), $method.' '.$valid);

                foreach (['no-id', str_repeat('9', 19), str_repeat('9', 120)] as $invalid) {
                    $uri = str_replace('{id}', $invalid, $prefix.$fragment);
                    try {
                        Route::getRoutes()->match(Request::create($uri, $method));
                        $this->fail('Invalid charger ID dispatched: '.$method.' '.$uri);
                    } catch (NotFoundHttpException) {
                        // Invalid IDs are stopped before authorization or device logic.
                    }
                }
            }
        }
    }
}
