<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Finance refund-review uses a signed int controller argument. Match only
 * safe numeric IDs before authorization, tenant lookup or ledger mutation.
 * Tests route matching and malformed HTTP requests, never valid money actions.
 */
class RefundReviewRouteBoundaryTest extends TestCase
{
    public function test_refund_review_rejects_malformed_and_overflowing_ids_under_both_auth_mounts(): void
    {
        foreach (['/api', '/api/v1'] as $prefix) {
            $valid = $prefix.'/finance/refund-requests/12345/review';
            $this->assertNotNull(
                Route::getRoutes()->match(Request::create($valid, 'POST'))->getActionName()
            );

            foreach (['not-an-integer', '-1', '1e19', str_repeat('9', 19), str_repeat('9', 120)] as $bad) {
                $invalid = $prefix.'/finance/refund-requests/'.$bad.'/review';
                try {
                    Route::getRoutes()->match(Request::create($invalid, 'POST'));
                    $this->fail('Invalid refund review ID dispatched: '.$invalid);
                } catch (NotFoundHttpException) {
                    // No controller invocation, finance operation or network I/O.
                }
                $this->postJson($invalid, [])->assertNotFound();
            }
        }
    }
}
