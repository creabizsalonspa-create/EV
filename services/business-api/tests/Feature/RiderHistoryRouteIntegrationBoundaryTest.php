<?php

namespace Tests\Feature;

use App\Http\Controllers\RiderAccessController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Agent 1's optional token/grant activity actions and Agent 9's existing
 * bounded grant constraints must survive Agent 4's shared-file integration.
 * No rider token, grant, company, device, or database row is mutated here.
 */
class RiderHistoryRouteIntegrationBoundaryTest extends TestCase
{
    private function registered(string $method): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->getActionName() === RiderAccessController::class.'@'.$method) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function test_existing_grant_routes_keep_integer_limits_in_both_api_mounts(): void
    {
        foreach (['withdraw', 'review', 'provision'] as $action) {
            $registered = $this->registered($action);
            $this->assertCount(2, $registered, 'Both API mounts must retain '.$action);

            foreach ($registered as $route) {
                $parameter = $action === 'provision' ? 'token' : 'grant';
                $this->assertSame('[0-9]{1,18}', $route->wheres[$parameter] ?? null,
                    'Do not lose numeric bounds for '.$route->uri());
            }
        }
    }

    public function test_optional_rider_activity_routes_remain_available_and_fail_closed_when_integrated(): void
    {
        foreach (['activity', 'grantActivity'] as $action) {
            $registered = $this->registered($action);
            if (! method_exists(RiderAccessController::class, $action)) {
                $this->assertCount(0, $registered, 'Do not register an absent controller action');

                continue;
            }

            $this->assertCount(2, $registered,
                'Agent 1 activity feature must remain present under both authenticated API mounts');
            foreach ($registered as $route) {
                $this->assertContains('GET', $route->methods());
                if ($action === 'grantActivity') {
                    $this->assertSame('[0-9]{1,18}', $route->wheres['grant'] ?? null,
                        'Grant activity has an int $grant handler and must reject oversized IDs');
                } else {
                    $this->assertContains('token', $route->parameterNames(),
                        'Token activity uses a RiderAccessToken model binding');
                }
            }

            foreach (['/api', '/api/v1'] as $prefix) {
                if ($action === 'activity') {
                    $uri = $prefix.'/rider-access/tokens/1/activity';
                    $this->assertSame(
                        RiderAccessController::class.'@activity',
                        Route::getRoutes()->match(Request::create($uri, 'GET'))->getActionName()
                    );
                } else {
                    $valid = $prefix.'/vendor-ops/companies/1/rider-grants/12345/activity';
                    $this->assertSame(
                        RiderAccessController::class.'@grantActivity',
                        Route::getRoutes()->match(Request::create($valid, 'GET'))->getActionName()
                    );

                    foreach ([str_repeat('9', 19), str_repeat('9', 120), '1e20', '-1'] as $bad) {
                        $uri = $prefix.'/vendor-ops/companies/1/rider-grants/'.$bad.'/activity';
                        try {
                            Route::getRoutes()->match(Request::create($uri, 'GET'));
                            $this->fail('Invalid grant activity ID reached a controller: '.$uri);
                        } catch (NotFoundHttpException) {
                            // Fail at routing, before touching rider or grant history.
                        }
                    }
                }
            }
        }
    }
}
