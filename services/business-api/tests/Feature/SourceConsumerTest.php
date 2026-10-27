<?php

namespace Tests\Feature;

use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\SessionBridge;
use App\Services\Charging\SourceConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SourceConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'isolated-consumer-fixture-key-at-least-32';

    private function link(): SessionLink
    {
        config(['csms.session_bridge_enabled' => true, 'csms.event_secret' => self::KEY, 'csms.graphql_secret' => self::KEY]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Consumer fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-consumer', 'protocol' => '2.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $booking = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinute(), 'idempotency_key' => 'source-fixture']);
        app(SessionBridge::class)->start($booking, $customer->id);

        return SessionLink::firstOrFail();
    }

    public function test_source_authentication_signed_delivery_restart_cursor_and_final_reconciliation(): void
    {
        $link = $this->link();
        $at = now()->startOfSecond();
        $tx = ['id' => 10, 'transactionId' => 'fixture-transaction', 'remoteStartId' => $link->start_command_id, 'meterStart' => '1.000000', 'startTime' => $at->toIso8601String(), 'endTime' => null, 'isActive' => true, 'totalKwh' => null];
        $rows = [
            ['id' => 1, 'timestamp' => $at->toIso8601String(), 'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]]],
            ['id' => 2, 'timestamp' => $at->addSecond()->toIso8601String(), 'sampledValue' => [['measurand' => 'Energy.Active.Import.Register', 'value' => '2500', 'context' => 'Sample.Periodic', 'unitOfMeasure' => ['unit' => 'Wh']]]],
            ['id' => 3, 'timestamp' => $at->addSeconds(2)->toIso8601String(), 'sampledValue' => [['measurand' => 'Energy.Active.Import.Register', 'value' => '3.500', 'context' => 'Transaction.End', 'unitOfMeasure' => ['unit' => 'kWh']]]],
        ];
        $loseEndResponse = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$tx, $rows, $link, &$loseEndResponse) {
            if ($request->url() === 'http://127.0.0.1:8095/v1/graphql') {
                $this->assertSame(self::KEY, $request->header('x-hasura-admin-secret')[0]);
                $query = $request['query'];
                if (str_contains($query, 'ChargingStations')) {
                    $this->assertSame('EV-SIM-'.$link->charging_session_id, $request['variables']['tag']);

                    return Http::response(['data' => ['ChargingStations' => [['id' => 8]], 'Authorizations' => [['id' => 9]]]]);
                }
                if (str_contains($query, 'Transactions')) {
                    $this->assertSame(['station' => 8, 'auth' => 9], $request['variables']);

                    return Http::response(['data' => ['Transactions' => [$tx]]]);
                }

                return Http::response(['data' => ['MeterValues' => array_values(array_filter($rows, fn ($r) => $r['id'] > $request['variables']['after']))]]);
            }
            $this->assertSame('http://127.0.0.1:8000/api/internal/csms/events', $request->url());
            $stamp = $request->header('X-EV-Timestamp')[0];
            $this->assertSame(hash_hmac('sha256', $stamp.'.'.$request->body(), self::KEY), $request->header('X-EV-Signature')[0]);
            $response = $this->call('POST', '/api/internal/csms/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_EV_TIMESTAMP' => $stamp, 'HTTP_X_EV_SIGNATURE' => $request->header('X-EV-Signature')[0]], $request->body());

            if ($loseEndResponse && $request['type'] === 'ended') {
                $loseEndResponse = false;

                return Http::response(['message' => 'fixture lost reply after commit'], 503);
            }

            return Http::response($response->json(), $response->getStatusCode());
        });
        $consumer = app(SourceConsumer::class);
        $this->assertSame(2, $consumer->consume($link));
        $this->assertSame(2, $link->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'charging', 'energy_wh' => 1500]);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(0, (new SourceConsumer)->consume($link->fresh()));
        $tx['isActive'] = false;
        $tx['endTime'] = $at->addSeconds(2)->toIso8601String();
        $tx['totalKwh'] = '2.500000';
        $loseEndResponse = true;
        try {
            $consumer->consume($link);
            $this->fail('Lost acknowledgement was ignored.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('finance_invoices', 1);
            $this->assertSame(2, $link->source_meter_cursor);
        }
        // Retry the exact persisted source identity after a lost response.
        $this->assertSame(1, $consumer->consume($link->fresh()));
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1); // Observing never dispatches/creates commands.
        $this->assertSame('pending', DB::table('charger_commands')->value('status'));
    }

    public function test_ambiguous_source_identity_is_rejected_without_advancing_business_state(): void
    {
        $link = $this->link();
        Http::fake(['*' => Http::response(['data' => ['ChargingStations' => [['id' => 1], ['id' => 2]], 'Authorizations' => []]])]);
        try {
            app(SourceConsumer::class)->consume($link);
            $this->fail('Ambiguous identity was accepted.');
        } catch (RuntimeException) {
            $this->assertSame(-1, $link->fresh()->last_sequence);
            $this->assertDatabaseCount('csms_inbox_events', 0);
        }
    }

    public function test_meter_units_are_exact_and_unsupported_precision_is_not_rounded(): void
    {
        $this->assertSame(2500, SourceConsumer::wh('2.500000', 'kWh'));
        $this->assertSame(2500, SourceConsumer::wh('2500.0', 'Wh'));
        foreach ([['0.5', 'Wh', 0], ['1.0001', 'kWh', 0], ['1', 'MWh', 0], ['1', 'Wh', 3], ['1e3', 'Wh', 0]] as $args) {
            try {
                SourceConsumer::wh(...$args);
                $this->fail('Unsupported meter was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
