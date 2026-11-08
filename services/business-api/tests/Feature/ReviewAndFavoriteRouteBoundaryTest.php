<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Unbounded digit strings must not reach customer-review/favorite handlers,
 * public station review discovery or locally gated checkout simulation pages.
 * The matcher check makes no database, provider or physical device mutations.
 */
class ReviewAndFavoriteRouteBoundaryTest extends TestCase
{
    public function test_review_and_favorite_identifiers_are_bounded_in_both_api_mounts(): void
    {
        $routes = [
            ['GET', '/stations/{id}/reviews'],
            ['POST', '/charging-reviews/sessions/{id}'],
            ['PUT', '/charging-reviews/{id}'],
            ['DELETE', '/charging-reviews/{id}'],
            ['GET', '/charging-reviews/{id}/history'],
            ['POST', '/charging-reviews/{id}/moderate'],
            ['PUT', '/favorites/{id}'],
            ['DELETE', '/favorites/{id}'],
        ];
        $this->assertCount(8, $routes);

        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ($routes as [$method, $template]) {
                $valid = $prefix.str_replace('{id}', '12345', $template);
                $this->assertNotNull(Route::getRoutes()->match(Request::create($valid, $method))->getActionName());

                foreach (['not-an-id', '-1', str_repeat('9', 19), str_repeat('9', 120)] as $bad) {
                    $uri = $prefix.str_replace('{id}', $bad, $template);
                    try {
                        Route::getRoutes()->match(Request::create($uri, $method));
                        $this->fail('Invalid review/favorite ID dispatched: '.$method.' '.$uri);
                    } catch (NotFoundHttpException) {
                        // Reject before model binding, account access or mutation.
                    }
                }
            }

            // Public catalog routes also return actual 404 responses rather than 500.
            $this->getJson($prefix.'/stations/'.str_repeat('9', 19).'/reviews')->assertNotFound();
        }
    }

    public function test_hosted_simulation_pages_reject_oversized_checkout_ids_before_auth(): void
    {
        foreach (['/payments/hosted-simulation/{id}', '/payments/corporate-hosted-simulation/{id}'] as $template) {
            $valid = str_replace('{id}', '12345', $template);
            $this->assertNotNull(Route::getRoutes()->match(Request::create($valid, 'GET'))->getActionName());

            foreach (['invalid', '-1', str_repeat('9', 19), str_repeat('9', 120)] as $bad) {
                $uri = str_replace('{id}', $bad, $template);
                try {
                    Route::getRoutes()->match(Request::create($uri, 'GET'));
                    $this->fail('Invalid simulation checkout ID dispatched: '.$uri);
                } catch (NotFoundHttpException) {
                    // An oversized ID is not an authenticated simulation.
                }
            }
        }
    }
}
