<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Protected campaign, loyalty, recurring-payment, subscription and rider
 * routes, plus v1 sandbox roaming routes, must reject malformed/oversized
 * numeric IDs before controller, payment, grant or external-provider logic.
 * Route matching here never dispatches financial or device operations.
 */
class RemainingTypedRouteBoundaryTest extends TestCase
{
    private function assertBounded(string $method, string $template, array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            $valid = $prefix.str_replace('{id}', '12345', $template);
            $this->assertNotNull(
                Route::getRoutes()->match(Request::create($valid, $method))->getActionName(),
                'Expected valid guarded route: '.$method.' '.$valid
            );

            foreach (['non-numeric', '-1', str_repeat('9', 19), str_repeat('9', 120)] as $bad) {
                $invalid = $prefix.str_replace('{id}', $bad, $template);
                try {
                    Route::getRoutes()->match(Request::create($invalid, $method));
                    $this->fail('Invalid numeric ID dispatched: '.$method.' '.$invalid);
                } catch (NotFoundHttpException) {
                    // Fail closed at routing, before any auth, DB or network I/O.
                }
            }
        }
    }

    public function test_remaining_finance_rider_and_roaming_routes_are_bounded(): void
    {
        $finance = [
            ['GET', '/commercial/campaigns/{id}'],
            ['POST', '/commercial/campaigns/{id}/submit'],
            ['POST', '/commercial/loyalty/invoices/{id}/earn'],
            ['POST', '/commercial/loyalty/rewards/{id}/redeem'],
            ['GET', '/commercial/loyalty/redemptions/{id}'],
            ['POST', '/commercial/loyalty/redemptions/{id}/fulfill'],
            ['GET', '/commercial/payment-recovery/{id}/events'],
            ['POST', '/commercial/payment-recovery/{id}/reconcile'],
            ['POST', '/commercial/payment-enrollments/{id}/inspect'],
            ['POST', '/commercial/payment-methods/{id}/default'],
            ['DELETE', '/commercial/payment-methods/{id}'],
            ['POST', '/commercial/payment-methods/{id}/detach'],
            ['PUT', '/commercial/subscriptions/{id}/collection-policy'],
            ['POST', '/commercial/subscription-checkouts/{id}/authentication'],
            ['GET', '/commercial/subscriptions/{id}/credits'],
            ['POST', '/commercial/subscriptions/{id}/credits'],
            ['GET', '/commercial/subscription-credits/{id}'],
            ['PATCH', '/commercial/plans/{id}/availability'],
            ['GET', '/commercial/subscriptions/{id}'],
            ['POST', '/commercial/subscriptions/{id}/change'],
            ['POST', '/commercial/subscriptions/{id}/mock-payment'],
            ['POST', '/commercial/subscriptions/{id}/renew'],
            ['GET', '/commercial/subscriptions/{id}/events'],
        ];
        $rider = [
            ['DELETE', '/rider-access/tokens/1/grants/{id}'],
            ['POST', '/vendor-ops/companies/1/rider-grants/{id}/review'],
            ['POST', '/vendor-ops/companies/1/rider-tokens/{id}/provision'],
        ];
        $roaming = [
            ['GET', '/locations/{id}/sandbox-offers'],
            ['POST', '/locations/{id}/sandbox-orders'],
            ['GET', '/sandbox-orders/{id}'],
            ['POST', '/sandbox-orders/{id}/reconcile'],
            ['POST', '/sandbox-orders/{id}/stop'],
            ['DELETE', '/sandbox-orders/{id}/authority'],
        ];
        $this->assertCount(23, $finance);
        $this->assertCount(3, $rider);
        $this->assertCount(6, $roaming);

        foreach ($finance as [$method, $template]) {
            $this->assertBounded($method, $template, ['/api', '/api/v1']);
        }
        foreach ($rider as [$method, $template]) {
            $this->assertBounded($method, $template, ['/api', '/api/v1']);
        }
        foreach ($roaming as [$method, $template]) {
            $this->assertBounded($method, $template, ['/api/v1/roaming']);
        }
    }
}
