<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Bad commercial IDs must be rejected at route matching, before a typed
 * controller argument, tenant lookup, gateway request or finance mutation.
 * Runs against both the web-session and Sanctum-mounted API route families.
 */
class CommercialRouteParameterSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_and_oversized_commercial_route_ids_never_reach_controller_or_finance(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $initial = [];
        foreach (['gateway_cases', 'gateway_case_evidence', 'finance_payments', 'finance_refunds', 'audit_events'] as $table) {
            $initial[$table] = DB::table($table)->count();
        }

        $badIds = ['not-an-id', '-1', '1.0', '1e300', str_repeat('9', 19), str_repeat('9', 120)];
        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ($badIds as $id) {
                $requests = [
                    ['POST', $prefix.'/commercial/checkouts/'.$id.'/authorize'],
                    ['POST', $prefix.'/commercial/cases/'.$id.'/resolve'],
                    ['GET', $prefix.'/commercial/reconciliation/'.$id],
                    ['GET', $prefix.'/commercial/cases/'.$id.'/evidence'],
                    ['POST', $prefix.'/commercial/cases/'.$id.'/evidence'],
                    ['GET', $prefix.'/commercial/cases/1/evidence/'.$id],
                ];
                foreach ($requests as [$method, $uri]) {
                    try {
                        Route::getRoutes()->match(Request::create($uri, $method));
                        $this->fail('Malformed commercial ID reached a controller: '.$method.' '.$uri);
                    } catch (NotFoundHttpException) {
                        // Route constraints, not controller exception handling, reject it.
                    }
                    if ($method === 'GET') {
                        $this->getJson($uri)->assertNotFound();
                    } else {
                        $this->postJson($uri, [])->assertNotFound();
                    }
                }
            }
        }

        foreach ($initial as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed after invalid routing');
        }
        Http::assertNothingSent();
    }
}
