<?php

namespace Tests\Feature;

use App\Http\Controllers\RoamingOrderController;
use App\Models\User;
use App\Services\Ocpi\RoamingDispatch;
use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class OcpiRequestAuthorityTest extends TestCase
{
    use CreatesSandboxRoaming;
    use RefreshDatabase;

    private function denied(callable $call): void
    {
        try {
            $call();
            $this->fail('Captured revoked authority must not become a fresh login.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public static function accountChanges(): array
    {
        return [['auth_epoch', 9], ['password', 'changed-private-test-hash'], ['role', 'vendor'], ['account_status', 'suspended']];
    }

    #[DataProvider('accountChanges')]
    public function test_old_request_cannot_refresh_itself_into_new_roaming_authority(string $field, mixed $value): void
    {
        [$owner, $driver, , $provider, $location] = $this->fixture();
        [$id, $body] = $this->start($driver, $location);
        $service = app(SandboxRoaming::class);
        $dispatch = app(RoamingDispatch::class);
        $request = Request::create('/private-roaming', 'POST', $body + ['request_key' => (string) Str::uuid()]);
        $request->setUserResolver(fn () => $driver);
        DB::table('users')->where('id', $driver->id)->update([$field => $value]);
        $controller = app(RoamingOrderController::class);
        foreach ([['capabilities', [$request]], ['offers', [$request, $location, $service]], ['index', [$request, $service]], ['show', [$request, $id, $service]], ['create', [$request, $location, $service, $dispatch]], ['stop', [$request, $id, $service, $dispatch]], ['revoke', [$request, $id, $service, $dispatch]]] as [$method, $args]) {
            $this->denied(fn () => $controller->callAction($method, $args));
        }
        $this->denied(fn () => $service->create($driver, $location, $body)); // Even exact replay stays private.
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('ocpi_roaming_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    private function active(): array
    {
        [$owner, $driver, $other, $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $this->withHeaders($this->peer())->postJson('/api/roaming-protocol/2.2.1/tokens/'.$token['uid'].'/authorize?type=APP_USER', ['location_id' => 'L1', 'evse_uids' => ['E1']])->assertOk();
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $this->sourceSessionFixture($provider, $order, $token))->assertCreated();
        $this->withHeader('Authorization', '')->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_active');

        return [$owner, $driver, $other, $provider, DB::table('ocpi_roaming_orders')->find($id)];
    }

    public function test_changed_actor_after_stop_claim_never_sends_then_fresh_explicit_stop_uses_a_new_identity(): void
    {
        [, $driver, , , $order] = $this->active();
        $armed = true;
        DB::listen(function ($query) use ($driver, &$armed) {
            if ($armed && str_contains(strtolower($query->sql), 'insert into "ocpi_roaming_commands"')) {
                $armed = false;
                DB::table('users')->where('id', $driver->id)->increment('auth_epoch');
            }
        });
        $key = (string) Str::uuid();
        app(RoamingDispatch::class)->stop($driver, $order, $key);
        $command = DB::table('ocpi_roaming_commands')->where('action', 'STOP_SESSION')->first();
        $this->assertSame('not_dispatched', $command->state);
        $this->assertNull($command->attempted_at);
        $this->assertCount(2, $this->writes);
        $fresh = $driver->fresh();
        $this->assertTrue(app(SandboxRoaming::class)->present($order, true)['can_stop']);
        app(RoamingDispatch::class)->stop($fresh, $order, $key); // Exact replay never resends.
        $this->assertCount(2, $this->writes);
        app(RoamingDispatch::class)->stop($fresh, $order, (string) Str::uuid());
        $this->assertCount(3, $this->writes);
        $this->assertSame('S1', $this->writes[2]['body']['session_id']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_source_deactivation_blocked_before_wire_requires_fresh_explicit_completion(): void
    {
        [, $driver, , , $order] = $this->active();
        DB::table('ocpi_roaming_orders')->where('id', $order->id)->update(['token_revoked_at' => now(), 'token_state' => 'local_revoked']);
        $order = DB::table('ocpi_roaming_orders')->find($order->id);
        $armed = true;
        DB::listen(function ($query) use ($driver, &$armed) {
            if ($armed && str_contains(strtolower($query->sql), 'insert into "ocpi_roaming_commands"')) {
                $armed = false;
                DB::table('users')->where('id', $driver->id)->increment('auth_epoch');
            }
        });
        $dispatch = app(RoamingDispatch::class);
        $dispatch->revokeToken($order, $driver);
        $command = DB::table('ocpi_roaming_commands')->where('action', 'TOKEN_REVOKE')->first();
        $this->assertSame('not_dispatched', $command->state);
        $this->assertNull($command->attempted_at);
        $this->assertCount(2, $this->writes);
        $this->assertTrue(app(SandboxRoaming::class)->present($order, true)['can_complete_source_revocation']);
        $dispatch->revokeToken($order, $driver->fresh());
        $this->assertCount(3, $this->writes);
        $this->assertFalse($this->writes[2]['body']['valid']);
        $this->assertSame($this->writes[0]['body']['uid'], $this->writes[2]['body']['uid']);
        $this->assertSame($command->intent_hash, DB::table('ocpi_roaming_commands')->find($command->id)->intent_hash);
        $dispatch->revokeToken($order, $driver->fresh());
        $this->assertCount(3, $this->writes);
        $fresh = DB::table('ocpi_roaming_orders')->find($order->id);
        $this->assertSame('source_active', $fresh->state);
        $this->assertNotNull($fresh->active_target_key);
        $this->assertFalse(app(SandboxRoaming::class)->present($fresh, true)['can_complete_source_revocation']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unknown_or_contradictory_command_facts_never_offer_another_wire_attempt(): void
    {
        [, $driver, , , $order] = $this->active();
        $dispatch = app(RoamingDispatch::class);
        // Entering the HTTP transport is conservatively unknown even if no reply arrives.
        Http::swap(new Factory);
        $wire = 0;
        Http::fake(function () use (&$wire) {
            $wire++;
            throw new ConnectionException('Synthetic missing receipt');
        });
        $dispatch->stop($driver, $order, (string) Str::uuid());
        $stop = DB::table('ocpi_roaming_commands')->where('action', 'STOP_SESSION')->first();
        $this->assertSame('outcome_unknown', $stop->state);
        $this->assertNotNull($stop->attempted_at);
        $this->assertFalse(app(SandboxRoaming::class)->present($order, true)['can_stop']);
        $this->denied(fn () => $dispatch->stop($driver, $order, (string) Str::uuid()));
        DB::table('ocpi_roaming_commands')->where('id', $stop->id)->update(['state' => 'not_dispatched']);
        $this->assertFalse(app(SandboxRoaming::class)->present($order, true)['can_stop']);
        DB::table('ocpi_roaming_orders')->where('id', $order->id)->update(['token_revoked_at' => now(), 'token_state' => 'local_revoked']);
        $order = DB::table('ocpi_roaming_orders')->find($order->id);
        $dispatch->revokeToken($order, $driver);
        $revoke = DB::table('ocpi_roaming_commands')->where('action', 'TOKEN_REVOKE')->first();
        $this->assertSame('outcome_unknown', $revoke->state);
        $this->assertFalse(app(SandboxRoaming::class)->present($order, true)['can_complete_source_revocation']);
        DB::table('ocpi_roaming_commands')->where('id', $revoke->id)->update(['state' => 'not_dispatched']);
        $this->assertFalse(app(SandboxRoaming::class)->knownUnsent(DB::table('ocpi_roaming_commands')->find($revoke->id)));
        $dispatch->revokeToken($order, $driver);
        $this->assertSame(2, $wire);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_controller_does_not_hold_application_transactions_across_partner_http(): void
    {
        [, $driver, , , $location] = $this->fixture();
        $baseline = DB::transactionLevel(); // RefreshDatabase owns the outer test transaction.
        $methods = [];
        Http::swap(new Factory);
        Http::fake(function ($request) use ($baseline, &$methods) {
            $this->assertSame($baseline, DB::transactionLevel());
            $methods[] = $request->method();

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $request->method() === 'PUT' ? null : ['result' => 'ACCEPTED', 'timeout' => 30]]);
        });
        [$id] = $this->start($driver, $location);
        $this->deleteJson('/api/roaming/sandbox-orders/'.$id.'/authority')->assertOk()->assertJsonPath('physical_stop_implied', false);
        $this->assertSame(['PUT', 'POST', 'PUT'], $methods);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_provider_reassignment_does_not_expose_previous_vendors_customer_history(): void
    {
        [$owner, $driver, , $provider, $location] = $this->fixture();
        $this->start($driver, $location);
        $newOwner = User::factory()->create(['role' => 'vendor']);
        $provider->update(['owner_id' => $newOwner->id]);
        $path = '/api/ocpi/providers/'.$provider->id.'/sandbox-orders';
        $this->actingAs($newOwner)->getJson($path)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson($path)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(2, $this->writes);
    }
}
