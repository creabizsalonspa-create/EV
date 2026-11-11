<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Corporate/hosted/dispute operation IDs must be rejected before a typed
 * integer controller argument, provider call, or ledger mutation. This test
 * only matches routes and never dispatches a payment action.
 */
class CorporateHostedDisputeRouteBoundaryTest extends TestCase
{
    public function test_corporate_hosted_and_dispute_integer_ids_are_bounded_under_both_api_mounts(): void
    {
        $paths = [
            ['POST', '/commercial/corporate/fleets/{id}/agreements'],
            ['POST', '/commercial/corporate/agreements/{id}/accept'],
            ['GET', '/commercial/corporate/agreements/{id}/claims'],
            ['POST', '/commercial/corporate/agreements/{id}/claims'],
            ['POST', '/commercial/corporate/claims/{id}/review'],
            ['GET', '/commercial/corporate/agreements/{id}/statements'],
            ['POST', '/commercial/corporate/agreements/{id}/statements'],
            ['GET', '/commercial/corporate/statements/{id}'],
            ['GET', '/commercial/corporate/statements/{id}/export'],
            ['POST', '/commercial/corporate/statements/{id}/simulate-payment'],
            ['GET', '/commercial/hosted-checkouts/{id}'],
            ['GET', '/commercial/hosted-checkouts/{id}/events'],
            ['POST', '/commercial/hosted-checkouts/{id}/inspect'],
            ['POST', '/commercial/hosted-checkouts/{id}/capture'],
            ['POST', '/commercial/hosted-checkouts/{id}/cancel'],
            ['POST', '/commercial/hosted-checkouts/{id}/simulate'],
            ['POST', '/commercial/hosted-checkouts/{id}/retry-preparation'],
            ['GET', '/commercial/corporate/hosted-checkouts/{id}'],
            ['GET', '/commercial/corporate/hosted-checkouts/{id}/events'],
            ['POST', '/commercial/corporate/hosted-checkouts/{id}/inspect'],
            ['POST', '/commercial/corporate/hosted-checkouts/{id}/capture'],
            ['POST', '/commercial/corporate/hosted-checkouts/{id}/cancel'],
            ['POST', '/commercial/corporate/hosted-checkouts/{id}/simulate'],
            ['POST', '/commercial/corporate/hosted-checkouts/{id}/retry-preparation'],
            ['GET', '/commercial/external-disputes/{id}'],
            ['POST', '/commercial/external-disputes/{id}/reconcile'],
            ['POST', '/commercial/external-disputes/{id}/evidence'],
            ['POST', '/commercial/external-disputes/{id}/evidence/1/retry'],
            ['POST', '/commercial/external-disputes/1/evidence/{id}/retry'],
        ];
        $this->assertCount(29, $paths);

        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ($paths as [$method, $template]) {
                $valid = $prefix.str_replace('{id}', '12345', $template);
                $this->assertNotNull(
                    Route::getRoutes()->match(Request::create($valid, $method))->getActionName(),
                    'Expected route to remain available: '.$method.' '.$valid
                );

                foreach (['not-a-number', '-1', str_repeat('9', 19), str_repeat('9', 120)] as $bad) {
                    $invalid = $prefix.str_replace('{id}', $bad, $template);
                    try {
                        Route::getRoutes()->match(Request::create($invalid, $method));
                        $this->fail('Invalid corporate/dispute ID reached a controller: '.$method.' '.$invalid);
                    } catch (NotFoundHttpException) {
                        // Denied at the router without running a financial operation.
                    }
                }
            }
        }
    }
}
