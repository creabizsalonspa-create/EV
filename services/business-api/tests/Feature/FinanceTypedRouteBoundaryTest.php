<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * A signed 64-bit PHP controller argument must never receive a longer
 * all-digit route ID through the authenticated finance mounts. No DB,
 * payment gateway, or customer account is accessed by this matcher test.
 */
class FinanceTypedRouteBoundaryTest extends TestCase
{
    public function test_sensitive_finance_routes_reject_oversized_ids_before_controller_dispatch(): void
    {
        $routes = [
            ['POST', '/commercial/vendors/{id}/payout-accounts'],
            ['POST', '/commercial/payout-accounts/{id}/consent'],
            ['POST', '/commercial/payout-accounts/{id}/verify'],
            ['POST', '/commercial/settlements/{id}/payouts'],
            ['GET', '/commercial/payouts/{id}'],
            ['POST', '/commercial/payouts/{id}/execute'],
            ['POST', '/commercial/payout-accounts/{id}/onboarding'],
            ['GET', '/commercial/vendors/{id}/statement'],
            ['PUT', '/commercial/vendors/{id}/schedule'],
            ['POST', '/commercial/vendors/{id}/settlements'],
            ['POST', '/commercial/vendors/{id}/adjustments'],
            ['POST', '/commercial/adjustments/{id}/approve'],
            ['GET', '/commercial/settlements/{id}'],
            ['POST', '/commercial/settlements/{id}/approve'],
            ['POST', '/commercial/subscriptions/{id}/checkouts'],
            ['POST', '/commercial/subscription-checkouts/{id}/authorize'],
            ['GET', '/commercial/subscription-checkouts/{id}/reconciliation'],
            ['GET', '/financial/documents/{id}'],
            ['GET', '/financial/documents/{id}/download/pdf'],
        ];
        $this->assertCount(19, $routes);

        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ($routes as [$method, $uri]) {
                $valid = $prefix.str_replace('{id}', '12345', $uri);
                $matched = Route::getRoutes()->match(Request::create($valid, $method));
                $this->assertNotNull($matched->getActionName(), 'Expected valid finance route: '.$valid);

                foreach (['not-a-number', '-1', str_repeat('9', 19), str_repeat('9', 120)] as $bad) {
                    $invalid = $prefix.str_replace('{id}', $bad, $uri);
                    try {
                        Route::getRoutes()->match(Request::create($invalid, $method));
                        $this->fail('Malformed finance ID dispatched: '.$method.' '.$invalid);
                    } catch (NotFoundHttpException) {
                        // The router rejects before invoking finance/payment services.
                    }
                }
            }
        }
    }
}
