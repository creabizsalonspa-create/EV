<?php

namespace Tests\Feature;

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\SessionBridge;
use App\Services\Charging\SourceConsumer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SourceConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'isolated-consumer-fixture-key-at-least-32';

    private function link(string $protocol = '2.0.1'): SessionLink
    {
        config(['csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql', 'csms.session_bridge_enabled' => true, 'csms.event_secret' => self::KEY, 'csms.graphql_secret' => self::KEY]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Consumer fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-consumer', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $booking = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinute(), 'idempotency_key' => 'source-fixture']);
        app(SessionBridge::class)->start($booking, $customer->id);

        return SessionLink::firstOrFail();
    }

    public function test_actual_hasura_numeric_columns_preserve_their_wire_decimals_without_float_conversion(): void
    {
        $link = $this->link('1.6');
        $at = now()->toImmutable()->startOfSecond()->toIso8601String();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $at) {
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => 8, 'protocol' => '1.6']], 'Authorizations' => [['id' => 9]]]]);
            }
            if (str_contains($request['query'], 'Transactions')) {
                // Literal HTTP body: Hasura returns PostgreSQL numeric columns as
                // JSON numbers, not the decimal strings used by earlier fakes.
                $tx = ['id' => 10, 'transactionId' => '101', 'remoteStartId' => $link->start_command_id,
                    'meterStart' => '__BASELINE__', 'startTime' => $at, 'endTime' => null, 'isActive' => true, 'totalKwh' => '__TOTAL__'];
                $wire = str_replace(['"__BASELINE__"', '"__TOTAL__"'], ['1.250000', '1.500000'], json_encode(['data' => ['Transactions' => [$tx]]]));

                return Http::response($wire);
            }

            return Http::response(['data' => ['MeterValues' => []]]);
        });
        $this->assertSame(1, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(1250, $link->fresh()->meter_start_wh);
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'energy_wh' => 0, 'status' => 'charging']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function inboxModes(): array
    {
        return [
            'ocpp201_remote' => ['2.0.1', false],
            'ocpp201_local' => ['2.0.1', true],
            'ocpp16_remote' => ['1.6', false],
            'ocpp16_local' => ['1.6', true],
        ];
    }

    #[DataProvider('inboxModes')]
    public function test_source_authentication_signed_delivery_restart_cursor_and_final_reconciliation(string $protocol, bool $localInbox): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $tx = ['id' => 10, 'transactionId' => $protocol === '1.6' ? '101' : 'fixture-transaction', 'remoteStartId' => $link->start_command_id, 'meterStart' => '1.000000', 'startTime' => $at->toIso8601String(), 'endTime' => null, 'isActive' => true, 'totalKwh' => null];
        $rows = [
            ['id' => 1, 'timestamp' => $at->toIso8601String(), 'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]]],
            ['id' => 2, 'timestamp' => $at->addSecond()->toIso8601String(), 'sampledValue' => [['measurand' => 'Energy.Active.Import.Register', 'value' => '2500', 'context' => 'Sample.Periodic', 'unitOfMeasure' => ['unit' => 'Wh']]]],
            ['id' => 3, 'timestamp' => $at->addSecond()->toIso8601String(), 'sampledValue' => [['measurand' => 'Energy.Active.Import.Register', 'value' => '3.500', 'context' => 'Transaction.End', 'unitOfMeasure' => ['unit' => 'kWh']]]],
        ];
        $loseEndResponse = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$tx, $rows, $link, &$loseEndResponse, $localInbox, $protocol) {
            if ($request->url() === 'http://127.0.0.1:8095/v1/graphql') {
                $this->assertSame(self::KEY, $request->header('x-hasura-admin-secret')[0]);
                $query = $request['query'];
                if (str_contains($query, 'ChargingStations')) {
                    $this->assertSame('EV-SIM-'.$link->charging_session_id, $request['variables']['tag']);

                    return Http::response(['data' => ['ChargingStations' => [['id' => 8, 'protocol' => $protocol]], 'Authorizations' => [['id' => 9]]]]);
                }
                if (str_contains($query, 'Transactions')) {
                    $this->assertSame(['station' => 8, 'auth' => 9], $request['variables']);

                    return Http::response(['data' => ['Transactions' => [$tx]]]);
                }

                return Http::response(['data' => ['MeterValues' => array_values(array_filter($rows, fn ($r) => $r['id'] > $request['variables']['after']))]]);
            }
            $this->assertFalse($localInbox, 'Local inbox must not make a nested HTTP callback.');
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
        $this->assertSame(2, $consumer->consume($link, $localInbox));
        $this->assertSame(2, $link->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'charging', 'energy_wh' => 1500]);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(0, (new SourceConsumer)->consume($link->fresh(), $localInbox));
        $tx['isActive'] = false;
        $tx['endTime'] = $at->addSeconds(2)->toIso8601String();
        $tx['totalKwh'] = '2.500000';
        if ($localInbox) {
            $this->assertSame(1, $consumer->consume($link, true));
            $this->assertSame(0, $consumer->consume($link->fresh(), true));
        } else {
            $loseEndResponse = true;
            try {
                $consumer->consume($link, $localInbox);
                $this->fail('Lost acknowledgement was ignored.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('finance_invoices', 1);
                $this->assertSame(2, $link->source_meter_cursor);
            }
            // The terminal source sample occurred before the source endTime,
            // which the signed inbox recorded as last_event_at. A lost reply
            // must not strand final cursor reconciliation or duplicate billing.
            // The daemon/one-shot selection must include already terminal
            // links with a lagging source cursor. A manual consumer invocation
            // previously worked, while the real worker silently skipped them.
            $this->assertNotNull($link->fresh()->ended_at);
            $this->assertSame(3, $link->fresh()->last_sequence);
            $this->assertSame(2, $link->fresh()->source_meter_cursor);
            $this->assertSame(0, Artisan::call('csms:consume', [
                '--once' => true, '--link' => (string) $link->id,
            ]));
            $this->assertSame(3, $link->fresh()->source_meter_cursor);
            $this->assertNotNull($link->fresh()->source_meter_cursor_at);
            // Once converged, even the unfiltered command never reopens or
            // duplicates the already finalized invoice.
            $this->assertSame(0, Artisan::call('csms:consume', [
                '--once' => true, '--link' => (string) $link->id,
            ]));
            $this->assertSame(0, $consumer->consume($link->fresh(), $localInbox));
        }
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1); // Observing never dispatches/creates commands.
        $this->assertSame('source_observed', DB::table('charger_commands')->value('status'));
        $this->assertDatabaseHas('command_source_observations', ['prior_status' => 'pending', 'observed_state' => 'started', 'command_acceptance_verified' => false]);
    }

    public static function invalidSourceTimes(): array
    {
        return [['naive_start'], ['naive_meter'], ['before_start'], ['after_end'], ['end_before_start']];
    }

    #[DataProvider('invalidSourceTimes')]
    public function test_invalid_transaction_or_meter_time_cannot_finalize_and_corrected_source_can_resume(string $kind): void
    {
        $link = $this->link();
        $at = now()->toImmutable()->startOfSecond();
        $tx = ['id' => 10, 'transactionId' => 'fixture-transaction', 'remoteStartId' => $link->start_command_id, 'meterStart' => '1.000000', 'startTime' => $at->toIso8601String(), 'endTime' => $at->addSeconds(2)->toIso8601String(), 'isActive' => false, 'totalKwh' => '2.500000'];
        $row = ['id' => 1, 'timestamp' => $tx['endTime'], 'sampledValue' => [['measurand' => 'Energy.Active.Import.Register', 'value' => '3.500', 'context' => 'Transaction.End', 'unitOfMeasure' => ['unit' => 'kWh']]]];
        $validTx = $tx;
        $validRow = $row;
        match ($kind) {
            'naive_start' => $tx['startTime'] = $at->format('Y-m-d H:i:s'),
            'naive_meter' => $row['timestamp'] = $at->addSeconds(2)->format('Y-m-d H:i:s'),
            'before_start' => $row['timestamp'] = $at->subSecond()->toIso8601String(),
            'after_end' => $row['timestamp'] = $at->addSeconds(3)->toIso8601String(),
            'end_before_start' => $tx['endTime'] = $at->subSecond()->toIso8601String(),
        };
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$tx, &$row) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => 8, 'protocol' => '2.0.1']], 'Authorizations' => [['id' => 9]]]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [$tx]]]);
            }

            return Http::response(['data' => ['MeterValues' => [$row]]]);
        });
        try {
            app(SourceConsumer::class)->consume($link, true);
            $this->fail('Invalid source chronology was accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertSame(0, (int) $link->fresh()->source_meter_cursor);
            $this->assertNull($link->fresh()->ended_at);
            $this->assertSame(0, DB::table('charging_sessions')->where('id', $link->charging_session_id)->value('energy_wh'));
        }
        $tx = $validTx;
        $row = $validRow;
        app(SourceConsumer::class)->consume($link->fresh(), true);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500]);
        $this->assertDatabaseCount('charger_commands', 1);
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

    public static function meterAuthorityChanges(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['endpoint', 'source_key', 'inbox_key', 'disabled'] as $kind) {
                $cases[] = [$protocol, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('meterAuthorityChanges')]
    public function test_meter_poll_rejects_changed_authority_and_recovers_from_original_cursor(string $protocol, string $kind): void
    {
        $link = $this->link($protocol);
        $this->freezeTime();
        $at = now()->toImmutable()->startOfSecond();
        $final = false;
        $change = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $kind, $at, &$final, &$change) {
            $this->assertSame('http://127.0.0.1:8095/v1/graphql', $request->url());
            $this->assertSame(self::KEY, $request->header('x-hasura-admin-secret')[0] ?? null);
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10, 'transactionId' => $protocol === '1.6' ? '101' : 'authority-meter-fixture',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000', 'startTime' => $at->toIso8601String(),
                    'endTime' => $final ? $at->addSeconds(2)->toIso8601String() : null,
                    'isActive' => ! $final, 'totalKwh' => $final ? '2.500' : null,
                ]]]]);
            }
            if ($change) {
                match ($kind) {
                    'endpoint' => config(['csms.graphql_url' => 'https://alternate.example.test/graphql']),
                    'source_key' => config(['csms.graphql_secret' => str_repeat('s', 64)]),
                    'inbox_key' => config(['csms.event_secret' => str_repeat('i', 64)]),
                    'disabled' => config(['csms.session_bridge_enabled' => false]),
                };
            }

            return Http::response(['data' => ['MeterValues' => $final ? [[
                'id' => 1, 'timestamp' => $at->addSeconds(2)->toIso8601String(),
                'sampledValue' => [['measurand' => 'Energy.Active.Import.Register',
                    'value' => '3500', 'context' => 'Transaction.End', 'unitOfMeasure' => ['unit' => 'Wh']]],
            ]] : []]]);
        });
        $this->assertSame(1, app(SourceConsumer::class)->consume($link, true));
        $final = true;
        $change = true;
        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('A changed source authority finalized billing.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Source authority changed', $error->getMessage());
        }
        $this->assertSame(0, $link->fresh()->source_meter_cursor);
        $this->assertNull($link->fresh()->ended_at);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'charging', 'energy_wh' => 0]);
        $this->assertDatabaseCount('charger_commands', 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'alternate.example.test'));
        config(['csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql', 'csms.graphql_secret' => self::KEY,
            'csms.event_secret' => self::KEY, 'csms.session_bridge_enabled' => true]);
        $change = false;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(1, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
    }

    public static function protocolMismatchCases(): array
    {
        return [
            'ocpp16_reports_201' => ['1.6', '2.0.1'],
            'ocpp201_reports_16' => ['2.0.1', '1.6'],
            'ocpp16_missing_source_protocol' => ['1.6', null],
            'ocpp201_unrecognized_source_protocol' => ['2.0.1', 'OCPP2.0.1-unknown'],
        ];
    }

    #[DataProvider('protocolMismatchCases')]
    public function test_source_protocol_conflict_blocks_events_and_can_resume_only_after_corrected_source(string $protocol, ?string $reported): void
    {
        $link = $this->link($protocol);
        $at = now()->startOfSecond()->toIso8601String();
        $transactionQueries = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $at, &$reported, &$transactionQueries) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                $this->assertStringContainsString('{id protocol}', $query);

                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $reported]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                $transactionQueries++;

                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $link->protocol === '1.6' ? '101' : 'source-protocol-fixture',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at,
                    'endTime' => null,
                    'isActive' => true,
                    'totalKwh' => null,
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                return Http::response(['data' => ['MeterValues' => []]]);
            }

            $this->fail('Source protocol verification must not dispatch HTTP commands or external inbox requests.');
        });

        try {
            app(SourceConsumer::class)->consume($link, true);
            $this->fail('A mismatched or missing source protocol was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('protocol mismatch', $exception->getMessage());
        }
        $this->assertSame(0, $transactionQueries);
        $this->assertSame(-1, $link->fresh()->last_sequence);
        $this->assertSame(0, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame('pending', DB::table('charger_commands')->value('status'));

        // A fresh independently sourced protocol observation allows the same
        // original session to continue without replaying the start command.
        $reported = 'ocpp'.$protocol;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(1, $transactionQueries);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame('charging', DB::table('charging_sessions')->value('status'));
        $this->assertSame('source_observed', DB::table('charger_commands')->value('status'));
        $this->assertDatabaseCount('charger_commands', 1);

        // Protocol identity is rechecked on every observation, even after
        // the original start has been applied to the canonical session.
        $reported = $protocol === '1.6' ? '2.0.1' : '1.6';
        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('A changed upstream station protocol was accepted mid-session.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('protocol mismatch', $exception->getMessage());
        }
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame('charging', DB::table('charging_sessions')->value('status'));
    }

    #[DataProvider('protocolVersions')]
    public function test_reprovisioned_station_with_same_identifier_and_protocol_cannot_splice_a_live_transaction(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $sourceId = 8;
        $transactionRowId = 10;
        $final = false;
        $transactionReads = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $at, &$sourceId, &$transactionRowId, &$final, &$transactionReads) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => $sourceId, 'protocol' => 'ocpp'.$protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                $transactionReads++;

                return Http::response(['data' => ['Transactions' => [[
                    'id' => $transactionRowId, 'transactionId' => $protocol === '1.6' ? '101' : 'source-station-identity',
                    'remoteStartId' => $link->start_command_id, 'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $final ? $at->addSeconds(2)->toIso8601String() : null,
                    'isActive' => ($final === false), 'totalKwh' => $final ? '2.500' : null,
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                return Http::response(['data' => ['MeterValues' => $final &&
                    (int) ($request['variables']['after'] ?? 0) === 0 ? [[
                        'id' => 1, 'timestamp' => $at->addSeconds(2)->toIso8601String(),
                        'sampledValue' => [[
                            'measurand' => 'Energy.Active.Import.Register',
                            'value' => '3500', 'context' => 'Transaction.End',
                            'unitOfMeasure' => ['unit' => 'Wh'],
                        ]],
                    ]] : []]]);
            }

            $this->fail('Source reader must not dispatch external commands.');
        });

        $this->assertSame(1, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertSame(10, $link->fresh()->source_transaction_database_id);
        $this->assertSame('charging', DB::table('charging_sessions')->value('status'));
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertSame(1, $transactionReads);

        // A different persisted CSMS row with the same connection name and
        // protocol cannot own an already-started source transaction.
        $sourceId = 9;
        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('Reprovisioned station identity was accepted mid-session.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('station identity', $e->getMessage());
        }
        $this->assertSame(1, $transactionReads);
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertSame(0, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('charger_commands', 1);

        // Reusing the exact protocol transactionId under another persisted
        // source row also cannot hijack the original canonical session.
        $sourceId = 8;
        $transactionRowId = 11;
        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('Changed source transaction row was accepted mid-session.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('source transaction row identity changed', $e->getMessage());
        }
        $this->assertSame(2, $transactionReads);
        $this->assertSame(10, $link->fresh()->source_transaction_database_id);
        $this->assertSame(0, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);

        // Independent confirmation of both original source row identities
        // permits finalization without replaying an uncertain charger command.
        $transactionRowId = 10;
        $final = true;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(1, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_legacy_started_session_cannot_silently_adopt_an_unpinned_source_station(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond()->toIso8601String();
        $transactionsRead = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $at, &$transactionsRead) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                $transactionsRead++;

                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10, 'transactionId' => $protocol === '1.6' ? '101' : 'legacy-source-pin',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000', 'startTime' => $at,
                    'endTime' => null, 'isActive' => true, 'totalKwh' => null,
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                return Http::response(['data' => ['MeterValues' => []]]);
            }

            $this->fail('Unexpected upstream call during legacy source reconciliation.');
        });

        $this->assertSame(1, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertSame(10, $link->fresh()->source_transaction_database_id);
        $this->assertSame(1, $transactionsRead);
        DB::table('csms_session_links')->where('id', $link->id)->update(['source_station_id' => null]);

        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('A legacy started transaction adopted an unknown external station.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Legacy source station identity', $e->getMessage());
        }
        $this->assertSame(1, $transactionsRead);
        $this->assertNull($link->fresh()->source_station_id);
        // Legacy started links also cannot adopt an unpinned source
        // Transactions.id from a later upstream projection.
        DB::table('csms_session_links')->where('id', $link->id)->update([
            'source_station_id' => 8, 'source_transaction_database_id' => null,
        ]);
        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('Legacy started session adopted an unknown external transaction row.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Legacy source transaction row identity', $e->getMessage());
        }
        $this->assertSame(2, $transactionsRead);
        $this->assertNull($link->fresh()->source_transaction_database_id);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertSame('charging', DB::table('charging_sessions')->value('status'));
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_distinct_source_row_ids_with_repeated_cumulative_energy_never_double_bill(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->startOfSecond()->toImmutable();
        $tx = [
            'id' => 10,
            'transactionId' => $protocol === '1.6' ? '101' : 'repeated-register-source',
            'remoteStartId' => $link->start_command_id,
            'meterStart' => '1.000',
            'startTime' => $at->toIso8601String(),
            'endTime' => $at->addSeconds(2)->toIso8601String(),
            'isActive' => false,
            'totalKwh' => '2.500',
        ];
        $sample = fn (int $id, int $wh, string $context, int $seconds) => [
            'id' => $id,
            'timestamp' => $at->addSeconds($seconds)->toIso8601String(),
            'sampledValue' => [[
                'measurand' => 'Energy.Active.Import.Register',
                'value' => (string) $wh,
                'context' => $context,
                'unitOfMeasure' => ['unit' => 'Wh'],
            ]],
        ];
        $rows = [
            $sample(2, 2500, 'Sample.Periodic', 1),
            $sample(3, 2500, 'Sample.Periodic', 1),
            $sample(4, 3500, 'Transaction.End', 2),
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $tx, $rows) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $link->protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [$tx]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = $request['variables']['after'];
                $observations = array_values(array_filter($rows, fn ($row) => $row['id'] > $after));

                return Http::response(['data' => ['MeterValues' => $observations]]);
            }

            $this->fail('Observer attempted an external write instead of the signed local inbox.');
        });

        $this->assertSame(4, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(4, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseCount('csms_inbox_events', 4);
        $this->assertSame([2500, 2500], DB::table('csms_inbox_events')
            ->where('type', 'meter')->orderBy('sequence')->pluck('meter_wh')->all());
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id,
            'energy_wh' => 2500,
            'status' => 'stopped',
        ]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
    }

    public static function protocolVersions(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocolVersions')]
    public function test_one_source_transaction_row_cannot_be_assigned_to_two_canonical_sessions(string $protocol): void
    {
        $link = $this->link($protocol);
        $link->update([
            'source_station_id' => 8,
            'source_transaction_database_id' => 10,
        ]);
        $original = ChargingSession::findOrFail($link->charging_session_id);
        $booking = Reservation::findOrFail($original->reservation_id);
        $later = Reservation::create([
            'user_id' => $booking->user_id,
            'station_id' => $booking->station_id,
            'starts_at' => now()->addDays(2),
            'idempotency_key' => 'second-upstream-source-'.$protocol,
        ]);
        $second = ChargingSession::create([
            'reservation_id' => $later->id,
            'status' => 'pending_start',
            'rate_minor_per_kwh' => $original->rate_minor_per_kwh,
            'currency' => $original->currency,
        ]);
        $other = SessionLink::create([
            'charging_session_id' => $second->id,
            'binding_id' => $link->binding_id,
            'start_command_id' => $link->start_command_id,
            'protocol' => $link->protocol,
            'identifier' => $link->identifier,
            'source_station_id' => 8,
            'source_transaction_database_id' => 11,
            'last_sequence' => -1,
        ]);
        $this->assertDatabaseCount('csms_session_links', 2);
        $this->assertSame(11, $other->fresh()->source_transaction_database_id);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);

        // Even if two workers miss an application-level collision check, the
        // database cannot assign the same authenticated CSMS row twice.
        $this->expectException(QueryException::class);
        $other->update(['source_transaction_database_id' => 10]);
    }

    #[DataProvider('protocolVersions')]
    public function test_source_target_proof_requires_a_typed_graphql_list_and_original_connector(string $protocol): void
    {
        $link = $this->link($protocol);
        $session = ChargingSession::findOrFail($link->charging_session_id);
        $booking = Reservation::findOrFail($session->reservation_id);
        $station = Station::findOrFail($booking->station_id);
        $company = VendorCompany::create([
            'owner_id' => $station->vendor_id, 'legal_name' => 'Synthetic target proof',
            'country' => 'AE', 'trade_license_number' => 'SOURCE-TARGET', 'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $site = VendorSite::create([
            'company_id' => $company->id, 'station_id' => $station->id,
            'name' => 'Target fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai',
            'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55,
        ]);
        $charger = VendorCharger::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'TARGET-PROOF',
            'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol,
        ]);
        DB::table('csms_bindings')->where('id', $link->binding_id)
            ->update(['vendor_charger_id' => $charger->id]);
        $evse = Evse::create([
            'charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms',
            'binding_id' => $link->binding_id, 'source_evse_number' => $protocol === '1.6' ? 0 : 5,
        ]);
        $connector = VendorConnector::create([
            'charger_id' => $charger->id, 'connector_number' => 1,
            'connector_type' => 'CCS2', 'max_power_kw' => 50,
        ]);
        $map = ConnectorMapping::create([
            'station_id' => $station->id, 'evse_id' => $evse->id,
            'connector_id' => $connector->id, 'source_connector_number' => 7,
        ]);
        $booking->update(['connector_mapping_id' => $map->id, 'charging_evse_id' => $evse->id]);
        $session->update(['charging_evse_id' => $evse->id]);
        $link->update(['connector_mapping_id' => $map->id]);

        $at = now()->toImmutable()->startOfSecond();
        $bad = true;
        $repoint = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($protocol, $link, $map, $at, &$bad, &$repoint) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions(where:{id:')) {
                $row = ['connectorId' => 11];

                return Http::response(['data' => [
                    'Transactions' => $bad ? ['record' => $row] : [$row],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'mapped-target-proof',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSeconds(2)->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]]]]);
            }
            if (str_contains($query, 'Connectors')) {
                return Http::response(['data' => [
                    'Connectors' => [['connectorId' => 7]],
                ]]);
            }
            if (str_contains($query, 'OCPPMessages')) {
                $row = ['payload' => [
                    'eventType' => 'Started',
                    'transactionInfo' => [
                        'transactionId' => 'mapped-target-proof',
                        'remoteStartId' => $link->start_command_id,
                    ],
                    'evse' => ['id' => 5, 'connectorId' => 7],
                ]];

                return Http::response(['data' => [
                    'OCPPMessages' => $bad ? ['record' => $row] : [$row],
                ]]);
            }
            if (str_contains($query, 'MeterValues')) {
                if ($repoint) {
                    DB::table('charging_connector_mappings')->where('id', $map->id)->update(['source_connector_number' => 8]);
                }

                return Http::response(['data' => ['MeterValues' => [[
                    'id' => 1, 'timestamp' => $at->addSeconds(2)->toIso8601String(),
                    'sampledValue' => [[
                        'measurand' => 'Energy.Active.Import.Register',
                        'context' => 'Transaction.End', 'value' => '3500',
                        'unitOfMeasure' => ['unit' => 'Wh'],
                    ]],
                ]]]]);
            }

            $this->fail('Source target proof must not dispatch an unapproved command.');
        });

        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('Malformed source connector projection was accepted as physical target proof.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('source', strtolower($e->getMessage()));
        }
        $this->assertNull($link->fresh()->source_station_id);
        $this->assertNull($link->fresh()->source_transaction_database_id);
        $this->assertNull($link->fresh()->transaction_id);
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);

        $bad = false;
        $repoint = true;
        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('A connector repointed during the meter read was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Source target changed', $error->getMessage());
        }
        $this->assertSame(0, $link->fresh()->source_meter_cursor);
        $this->assertNull($link->fresh()->ended_at);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseHas('charging_sessions', ['id' => $link->charging_session_id, 'status' => 'charging', 'energy_wh' => 0]);
        DB::table('charging_connector_mappings')->where('id', $map->id)->update(['source_connector_number' => 7]);
        $repoint = false;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(1, $link->fresh()->source_meter_cursor);
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertSame(10, $link->fresh()->source_transaction_database_id);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
    }

    public function test_source_identity_and_meter_cursor_history_refuse_lossy_migration_rollback(): void
    {
        $link = $this->link();
        $cases = [
            ['2026_11_16_000001_pin_charging_source_station_id.php', 'source_station_id', 8],
            ['2026_11_16_000002_pin_source_transaction_database_row.php', 'source_transaction_database_id', 10],
            ['2026_11_16_000003_unique_pinned_source_transaction_row.php', 'source_transaction_database_id', 10],
            ['2026_11_16_000004_track_source_meter_cursor_time.php', 'source_meter_cursor_at',
                now()->utc()->format('Y-m-d H:i:s.u')],
        ];
        foreach ($cases as [$path, $column, $value]) {
            DB::table('csms_session_links')->where('id', $link->id)->update([$column => $value]);
            $this->assertTrue(DB::table('csms_session_links')->where('id', $link->id)
                ->whereNotNull($column)->exists());
            $migration = require database_path('migrations/'.$path);
            try {
                $migration->down();
                $this->fail('Retained source identity and cursor history must block schema rollback.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('before rollback', $error->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('csms_session_links', $column));
            $this->assertTrue(DB::table('csms_session_links')->where('id', $link->id)
                ->whereNotNull($column)->exists());
            if ($path === '2026_11_16_000003_unique_pinned_source_transaction_row.php') {
                $this->assertTrue(Schema::hasIndex('csms_session_links', 'csms_link_binding_source_tx_unique'));
            }
            DB::table('csms_session_links')->where('id', $link->id)->update([$column => null]);
        }
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function invalidSourceProjections(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['missing_stations', 'nonlist_authorizations', 'string_authorization_id', 'missing_transactions', 'nonlist_transactions', 'non_array_transaction', 'missing_transaction_id', 'missing_meter_start', 'missing_start_time', 'missing_end_time', 'missing_is_active', 'missing_total_kwh', 'array_remote_start_id', 'boolean_meter_start', 'array_start_time', 'boolean_total_kwh', 'invalid_meter_start_precision', 'invalid_total_kwh_precision',
                'invalid_protocol_transaction_id', 'oversized_protocol_transaction_id'] as $kind) {
                $cases[$protocol.'_'.$kind] = [$protocol, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidSourceProjections')]
    public function test_malformed_source_projections_never_start_a_session_and_recover_only_with_valid_source(string $protocol, string $kind): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $bad = true;
        $transactionReads = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($protocol, $link, $at, $kind, &$bad, &$transactionReads) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                $data = [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ];
                if ($bad && $kind === 'missing_stations') {
                    unset($data['ChargingStations']);
                }
                if ($bad && $kind === 'nonlist_authorizations') {
                    $data['Authorizations'] = ['record' => ['id' => 9]];
                }
                if ($bad && $kind === 'string_authorization_id') {
                    $data['Authorizations'] = [['id' => '9']];
                }

                return Http::response(['data' => $data]);
            }
            if (str_contains($query, 'Transactions')) {
                $transactionReads++;
                $records = [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'malformed-projection',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSeconds(2)->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]];
                $data = ['Transactions' => $records];
                if ($bad && $kind === 'missing_transactions') {
                    unset($data['Transactions']);
                }
                if ($bad && $kind === 'nonlist_transactions') {
                    $data['Transactions'] = ['record' => $records[0]];
                }
                if ($bad && $kind === 'non_array_transaction') {
                    $data['Transactions'] = [null];
                }
                $missingFields = [
                    'missing_transaction_id' => 'transactionId',
                    'missing_meter_start' => 'meterStart',
                    'missing_start_time' => 'startTime',
                    'missing_end_time' => 'endTime',
                    'missing_is_active' => 'isActive',
                    'missing_total_kwh' => 'totalKwh',
                ];
                if ($bad && isset($missingFields[$kind])) {
                    unset($data['Transactions'][0][$missingFields[$kind]]);
                }
                if ($bad && $kind === 'array_remote_start_id') {
                    // A one-element array coerces to integer 1 in PHP. The
                    // first simulator start command has ID 1, so coercion
                    // could otherwise fabricate a matching OCPP 2.0.1 start.
                    $data['Transactions'][0]['remoteStartId'] = [$link->start_command_id];
                }
                if ($bad && $kind === 'boolean_meter_start') {
                    // True casts to "1", which could create a false 1000 Wh
                    // baseline and an otherwise matching terminal invoice.
                    $data['Transactions'][0]['meterStart'] = true;
                }
                if ($bad && $kind === 'array_start_time') {
                    $data['Transactions'][0]['startTime'] = ['invalid'];
                }
                if ($bad && $kind === 'boolean_total_kwh') {
                    $data['Transactions'][0]['totalKwh'] = true;
                }
                if ($bad && $kind === 'invalid_meter_start_precision') {
                    $data['Transactions'][0]['meterStart'] = '1.0001';
                }
                if ($bad && $kind === 'invalid_total_kwh_precision') {
                    $data['Transactions'][0]['totalKwh'] = '2.5001';
                }
                if ($bad && $kind === 'invalid_protocol_transaction_id') {
                    $data['Transactions'][0]['transactionId'] = $protocol === '1.6' ? 'not-an-integer' : 101;
                }
                if ($bad && $kind === 'oversized_protocol_transaction_id') {
                    $data['Transactions'][0]['transactionId'] = $protocol === '1.6'
                        ? '2147483648' : str_repeat('x', 37);
                }

                return Http::response(['data' => $data]);
            }
            if (str_contains($query, 'MeterValues')) {
                return Http::response(['data' => ['MeterValues' => [[
                    'id' => 1,
                    'timestamp' => $at->addSeconds(2)->toIso8601String(),
                    'sampledValue' => [[
                        'measurand' => 'Energy.Active.Import.Register',
                        'value' => '3500',
                        'context' => 'Transaction.End',
                        'unitOfMeasure' => ['unit' => 'Wh'],
                    ]],
                ]]]]);
            }

            $this->fail('Malformed projection caused an unexpected outbound action.');
        });

        try {
            app(SourceConsumer::class)->consume($link, true);
            $this->fail('Malformed authenticated source projection was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('source', strtolower($e->getMessage()));
        }
        $this->assertNull($link->fresh()->transaction_id);
        $this->assertNull($link->fresh()->source_station_id);
        $this->assertNull($link->fresh()->source_transaction_database_id);
        $this->assertSame(0, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id, 'status' => 'pending_start', 'energy_wh' => 0,
        ]);
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);
        $bad = false;

        $this->assertSame(2, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(1, $link->fresh()->source_meter_cursor);
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertSame(10, $link->fresh()->source_transaction_database_id);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertGreaterThanOrEqual(1, $transactionReads);
    }

    public static function invalidMeterPages(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['duplicate_id', 'regressing_id', 'zero_id', 'oversized_id', 'string_id', 'non_absolute_time', 'future_non_energy', 'missing_samples', 'non_array_sample', 'non_list_samples', 'non_list_page', 'oversized_page', 'missing_page', 'nonenergy_before_start', 'nonenergy_after_end', 'later_ambiguous_register', 'later_invalid_unit', 'later_boolean_energy', 'later_inexact_kwh_energy', 'later_false_multiplier', 'later_float_multiplier', 'later_string_multiplier', 'later_null_multiplier', 'begin_meter_mismatch', 'begin_meter_boolean', 'later_after_end', 'later_regressing_nonenergy_time', 'later_regressing_energy_time'] as $kind) {
                $cases[$protocol.'_'.$kind] = [$protocol, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidMeterPages')]
    public function test_malformed_source_meter_pages_cannot_skip_evidence_or_finalize_after_restart(string $protocol, string $kind): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $stationQueries = 0;
        $transactionQueries = 0;
        $row = fn (int $id) => [
            'id' => $id, 'timestamp' => $at->toIso8601String(),
            'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]],
        ];
        $valid = [
            $row(1),
            ['id' => 2, 'timestamp' => $at->addSeconds(2)->toIso8601String(),
                'sampledValue' => [['measurand' => 'Energy.Active.Import.Register',
                    'value' => '3500', 'context' => 'Transaction.End',
                    'unitOfMeasure' => ['unit' => 'Wh']]]],
        ];
        $rows = match ($kind) {
            'duplicate_id' => [$row(1), $row(1)],
            'regressing_id' => [$row(2), $row(1)],
            'zero_id' => [$row(0)],
            'oversized_id' => [$row(1000000001)],
            'string_id' => [array_replace($row(1), ['id' => '1'])],
            'non_absolute_time' => [array_replace($row(1), ['timestamp' => '2026-11-15 10:00:00'])],
            'future_non_energy' => [array_replace($row(1), ['timestamp' => $at->addMinutes(6)->toIso8601String()])],
            'missing_samples' => [array_replace($row(1), ['sampledValue' => null])],
            'non_array_sample' => [array_replace($row(1), ['sampledValue' => [null]])],
            'non_list_samples' => [array_replace($row(1), ['sampledValue' => ['voltage' => ['measurand' => 'Voltage', 'value' => 230]]])],
            'non_list_page' => ['first' => $row(1)],
            'oversized_page' => array_map($row, range(1, 101)),
            'missing_page' => null,
            'nonenergy_before_start' => [array_replace($row(1), ['timestamp' => $at->subSecond()->toIso8601String()])],
            'nonenergy_after_end' => [array_replace($row(1), ['timestamp' => $at->addSeconds(3)->toIso8601String()])],
            'later_ambiguous_register' => [$row(1), array_replace($valid[1], ['sampledValue' => [$valid[1]['sampledValue'][0], $valid[1]['sampledValue'][0]]])],
            'later_invalid_unit' => [$row(1), array_replace($valid[1], ['sampledValue' => [array_replace($valid[1]['sampledValue'][0], ['unitOfMeasure' => ['unit' => 'MWh']])]])],
            'later_boolean_energy' => [$row(1), array_replace($valid[1], ['sampledValue' => [array_replace($valid[1]['sampledValue'][0], ['value' => true])]])],
            'later_inexact_kwh_energy' => [$row(1), array_replace($valid[1], ['sampledValue' => [array_replace($valid[1]['sampledValue'][0], [
                'value' => 3.5001, 'unitOfMeasure' => ['unit' => 'kWh'],
            ])]])],
            'later_false_multiplier' => [$row(1), array_replace($valid[1], ['sampledValue' => [array_replace($valid[1]['sampledValue'][0], ['unitOfMeasure' => ['unit' => 'kWh', 'multiplier' => false]])]])],
            'later_float_multiplier' => [$row(1), array_replace($valid[1], ['sampledValue' => [array_replace($valid[1]['sampledValue'][0], ['unitOfMeasure' => ['unit' => 'kWh', 'multiplier' => 0.0]])]])],
            'later_string_multiplier' => [$row(1), array_replace($valid[1], ['sampledValue' => [array_replace($valid[1]['sampledValue'][0], ['unitOfMeasure' => ['unit' => 'kWh', 'multiplier' => '0']])]])],
            'later_null_multiplier' => [$row(1), array_replace($valid[1], ['sampledValue' => [array_replace($valid[1]['sampledValue'][0], ['unitOfMeasure' => ['unit' => 'kWh', 'multiplier' => null]])]])],
            'begin_meter_mismatch' => [[
                'id' => 1, 'timestamp' => $at->toIso8601String(),
                'sampledValue' => [[
                    'measurand' => 'Energy.Active.Import.Register',
                    'value' => '900', 'context' => 'Transaction.Begin',
                    'unitOfMeasure' => ['unit' => 'Wh'],
                ]],
            ], $valid[1]],
            'begin_meter_boolean' => [[
                'id' => 1, 'timestamp' => $at->toIso8601String(),
                'sampledValue' => [[
                    'measurand' => 'Energy.Active.Import.Register',
                    'value' => true, 'context' => 'Transaction.Begin',
                    'unitOfMeasure' => ['unit' => 'Wh'],
                ]],
            ], $valid[1]],
            'later_after_end' => [$row(1), array_replace($valid[1], ['timestamp' => $at->addSeconds(3)->toIso8601String()])],
            'later_regressing_nonenergy_time' => [
                array_replace($row(1), ['timestamp' => $at->addSecond()->toIso8601String()]),
                $row(2),
            ],
            'later_regressing_energy_time' => [
                ['id' => 1, 'timestamp' => $at->addSecond()->toIso8601String(),
                    'sampledValue' => [['measurand' => 'Energy.Active.Import.Register',
                        'value' => '2500', 'context' => 'Sample.Periodic',
                        'unitOfMeasure' => ['unit' => 'Wh']]]],
                array_replace($valid[1], ['timestamp' => $at->toIso8601String()]),
            ],
        };
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($protocol, $link, $at, &$rows, &$stationQueries, &$transactionQueries) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                $stationQueries++;

                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => 'ocpp'.$protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                $transactionQueries++;

                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'source-meter-page',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSeconds(2)->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                // A real source query filters id > the durable cursor. After
                // finalization, replaying old rows would violate that contract.
                $after = (int) ($request['variables']['after'] ?? 0);

                return Http::response(json_encode(['data' => ['MeterValues' => $after > 0 ? [] : $rows]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }

            $this->fail('Source reader attempted an unauthorized network operation.');
        });

        try {
            app(SourceConsumer::class)->consume($link, true);
            $this->fail('Invalid source page was accepted or skipped.');
        } catch (RuntimeException) {
            $this->assertSame(0, $link->fresh()->source_meter_cursor);
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertDatabaseCount('charger_commands', 1);
        }
        $this->assertSame('charging', DB::table('charging_sessions')->value('status'));
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertSame(1, $stationQueries);
        $this->assertSame(1, $transactionQueries);

        // Re-examine the original first row, then finalize from genuine source
        // evidence. This does not replay the already observed start command.
        $rows = $valid;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(2, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id,
            'status' => 'stopped',
            'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertDatabaseCount('finance_invoices', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_terminal_register_excludes_later_same_page_diagnostics_for_both_ocpp_versions(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $meterQueries = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $at, &$meterQueries) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => 'ocpp'.$protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'terminal-diagnostics',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSeconds(2)->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $meterQueries++;
                $this->assertSame(0, (int) ($request['variables']['after'] ?? -1));
                $sample = fn (int $id, int $wh, string $context, int $seconds) => [
                    'id' => $id,
                    'timestamp' => $at->addSeconds($seconds)->toIso8601String(),
                    'sampledValue' => [[
                        'measurand' => 'Energy.Active.Import.Register',
                        'value' => (string) $wh,
                        'context' => $context,
                        'unitOfMeasure' => ['unit' => 'Wh'],
                    ]],
                ];

                return Http::response(['data' => ['MeterValues' => [
                    $sample(1, 2500, 'Sample.Periodic', 1),
                    $sample(2, 3500, 'Transaction.End', 2),
                    [
                        'id' => 3, 'timestamp' => $at->addSeconds(3)->toIso8601String(),
                        'sampledValue' => [['measurand' => 'Voltage', 'value' => 240]],
                    ],
                ]]]);
            }

            $this->fail('Unexpected network request after source finalization.');
        });

        $this->assertSame(3, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(2, $link->fresh()->source_meter_cursor);
        $this->assertSame(2, $link->fresh()->last_sequence);
        $this->assertNotNull($link->fresh()->ended_at);
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertSame(10, $link->fresh()->source_transaction_database_id);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('csms_inbox_events', 3);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);

        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(1, $meterQueries);
        $this->assertDatabaseCount('csms_inbox_events', 3);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_second_page_clock_regression_requires_review_and_recovers_from_original_source(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $bad = true;
        $afterQueries = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $at, &$bad, &$afterQueries) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'source-clock-page',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSeconds(3)->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = (int) ($request['variables']['after'] ?? -1);
                $afterQueries[] = $after;
                $sample = fn (int $id, int $wh, string $context, int $seconds) => [
                    'id' => $id,
                    'timestamp' => $at->addSeconds($seconds)->toIso8601String(),
                    'sampledValue' => [[
                        'measurand' => 'Energy.Active.Import.Register',
                        'value' => (string) $wh,
                        'context' => $context,
                        'unitOfMeasure' => ['unit' => 'Wh'],
                    ]],
                ];
                $page = match ($after) {
                    0 => [$sample(1, 2500, 'Sample.Periodic', 2)],
                    1 => [
                        [
                            'id' => 2,
                            'timestamp' => $at->addSeconds($bad ? 1 : 2)->toIso8601String(),
                            'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]],
                        ],
                        $sample(3, 3500, 'Transaction.End', 3),
                    ],
                    default => $this->fail('Terminal source reader attempted an unexpected page.'),
                };

                return Http::response(['data' => ['MeterValues' => $page]]);
            }

            $this->fail('Unexpected source request during clock regression verification.');
        });

        $this->assertSame(2, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(1, $link->fresh()->source_meter_cursor);
        $this->assertSame(1500, DB::table('charging_sessions')->value('energy_wh'));
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 0);

        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('Second-page timestamp regression was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chronology regressed', $e->getMessage());
        }
        $this->assertSame(1, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);

        $bad = false;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(3, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id, 'status' => 'stopped', 'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('csms_inbox_events', 3);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);

        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame([0, 1, 1], $afterQueries);
        $this->assertDatabaseCount('finance_invoices', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_exact_hundred_row_source_page_resumes_at_next_unread_energy_record(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $nonEnergy = array_map(fn (int $id) => [
            'id' => $id,
            'timestamp' => $at->toIso8601String(),
            'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]],
        ], range(1, 100));
        $last = [
            'id' => 101,
            'timestamp' => $at->addSecond()->toIso8601String(),
            'sampledValue' => [[
                'measurand' => 'Energy.Active.Import.Register',
                'value' => '3500',
                'context' => 'Transaction.End',
                'unitOfMeasure' => ['unit' => 'Wh'],
            ]],
        ];
        $requested = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($protocol, $link, $at, $nonEnergy, $last, &$requested) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'source-pagination',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSecond()->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = (int) ($request['variables']['after'] ?? -1);
                $requested[] = $after;
                $page = match ($after) {
                    0 => $nonEnergy,
                    100 => [$last],
                    101 => [[
                        'id' => 102,
                        'timestamp' => $at->addSeconds(2)->toIso8601String(),
                        'sampledValue' => [['measurand' => 'Voltage', 'value' => 240]],
                    ]],
                    default => $this->fail('Meter page was read with an unexpected source cursor.'),
                };

                return Http::response(['data' => ['MeterValues' => $page]]);
            }

            $this->fail('Source reader attempted an unexpected external request.');
        });

        $this->assertSame(1, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(100, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);

        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(101, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id,
            'status' => 'stopped',
            'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        // The terminal signed event makes later source rows irrelevant; there
        // must not be a third poll at cursor 101.
        $this->assertSame([0, 100], $requested);
        $this->assertDatabaseCount('finance_invoices', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_multi_page_cumulative_energy_with_250_source_rows_survives_restarts_without_duplicate_invoice(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        // Three persisted source pages, two periodic registers with identical
        // cumulative energy, and one authoritative terminal register.
        $rows = array_map(fn (int $id) => [
            'id' => $id,
            'timestamp' => $at->addSeconds($id >= 75 ? 1 : 0)->toIso8601String(),
            'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]],
        ], range(1, 250));
        $register = fn (int $id, string $value, string $context, int $offset) => [
            'id' => $id,
            'timestamp' => $at->addSeconds($offset)->toIso8601String(),
            'sampledValue' => [[
                'measurand' => 'Energy.Active.Import.Register',
                'value' => $value,
                'context' => $context,
                'unitOfMeasure' => ['unit' => 'Wh'],
            ]],
        ];
        $rows[74] = $register(75, '2500', 'Sample.Periodic', 1);
        $rows[149] = $register(150, '2500', 'Sample.Periodic', 1);
        $rows[249] = $register(250, '3500', 'Transaction.End', 2);
        $pagesRequested = [];

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $at, &$rows, &$pagesRequested) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'source-long-run',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSeconds(2)->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = (int) ($request['variables']['after'] ?? -1);
                $pagesRequested[] = $after;
                $this->assertContains($after, [0, 100, 200, 250]);

                return Http::response(['data' => [
                    'MeterValues' => array_slice($rows, $after, 100),
                ]]);
            }

            $this->fail('Unexpected external request during source load recovery.');
        });

        $this->assertSame(2, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(100, $link->fresh()->source_meter_cursor);
        $this->assertSame(1500, DB::table('charging_sessions')->value('energy_wh'));
        $this->assertDatabaseCount('finance_invoices', 0);

        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(200, $link->fresh()->source_meter_cursor);
        $this->assertSame(1500, DB::table('charging_sessions')->value('energy_wh'));
        $this->assertDatabaseCount('finance_invoices', 0);

        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(250, $link->fresh()->source_meter_cursor);
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id,
            'status' => 'stopped',
            'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('csms_inbox_events', 4);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame(8, $link->fresh()->source_station_id);

        // Source may retain additional post-terminal voltage telemetry.
        // The already-finalized canonical session must not poll or replay it.
        $rows[] = [
            'id' => 251, 'timestamp' => $at->addSeconds(3)->toIso8601String(),
            'sampledValue' => [['measurand' => 'Voltage', 'value' => 240]],
        ];
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame([0, 100, 200], $pagesRequested);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_stale_observer_cannot_rewind_a_more_recent_source_meter_cursor(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond()->toIso8601String();
        $requested = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($protocol, $link, $at, &$requested) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10, 'transactionId' => $protocol === '1.6' ? '101' : 'stale-source-reader',
                    'remoteStartId' => $link->start_command_id, 'meterStart' => '1.000',
                    'startTime' => $at, 'endTime' => null,
                    'isActive' => true, 'totalKwh' => null,
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = (int) ($request['variables']['after'] ?? -1);
                $requested[] = $after;
                if ($after === 0) {
                    // An independent observer committed a newer position
                    // after this worker issued the page read but before it
                    // advanced its own non-energy row cursor.
                    DB::table('csms_session_links')->where('id', $link->id)->update([
                        'source_meter_cursor' => 125,
                    ]);

                    return Http::response(['data' => ['MeterValues' => [[
                        'id' => 1,
                        'timestamp' => $at,
                        'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]],
                    ]]]]);
                }
                $this->assertSame(125, $after);

                return Http::response(['data' => ['MeterValues' => []]]);
            }
            $this->fail('Stale source worker attempted an unexpected HTTP request.');
        });

        $this->assertSame(1, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(125, $link->fresh()->source_meter_cursor);
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame('charging', DB::table('charging_sessions')->value('status'));

        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame(125, $link->fresh()->source_meter_cursor);
        $this->assertSame([0, 125], $requested);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);
    }

    public static function vanishedSourceIdentities(): array
    {
        return [
            'ocpp16_station_removed' => ['1.6', 'station'],
            'ocpp16_authorization_removed' => ['1.6', 'authorization'],
            'ocpp201_station_removed' => ['2.0.1', 'station'],
            'ocpp201_authorization_removed' => ['2.0.1', 'authorization'],
            'ocpp16_transaction_missing' => ['1.6', 'transaction'],
            'ocpp201_transaction_missing' => ['2.0.1', 'transaction'],
            'ocpp16_meter_start_missing' => ['1.6', 'meter_start'],
            'ocpp201_meter_start_missing' => ['2.0.1', 'meter_start'],
            'ocpp16_start_time_missing' => ['1.6', 'start_time'],
            'ocpp201_start_time_missing' => ['2.0.1', 'start_time'],
        ];
    }

    #[DataProvider('vanishedSourceIdentities')]
    public function test_started_session_requires_review_if_authenticated_source_identity_disappears(string $protocol, string $missing): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $remove = null;
        $ended = false;
        $transactionReads = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($protocol, $link, $at, &$remove, &$ended, &$transactionReads) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => $remove === 'station' ? [] : [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => $remove === 'authorization' ? [] : [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                $transactionReads++;
                if ($remove === 'transaction') {
                    return Http::response(['data' => ['Transactions' => []]]);
                }

                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'disappeared-source-fixture',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => $remove === 'meter_start' ? null : '1.000',
                    'startTime' => $remove === 'start_time' ? null : $at->toIso8601String(),
                    'endTime' => $ended ? $at->addSeconds(2)->toIso8601String() : null,
                    'isActive' => ($ended === false),
                    'totalKwh' => $ended ? '2.500' : null,
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = (int) ($request['variables']['after'] ?? -1);

                $samples = $ended && $after === 0 ? [[
                    'id' => 1, 'timestamp' => $at->addSeconds(2)->toIso8601String(),
                    'sampledValue' => [[
                        'measurand' => 'Energy.Active.Import.Register',
                        'value' => '3500',
                        'context' => 'Transaction.End',
                        'unitOfMeasure' => ['unit' => 'Wh'],
                    ]],
                ]] : [];

                return Http::response(['data' => ['MeterValues' => $samples]]);
            }

            $this->fail('A source identity check attempted unexpected outbound traffic.');
        });

        // An unstarted request may wait for upstream station or token
        // provisioning without falsely claiming an incident or a command.
        $remove = $missing;
        $this->assertSame(0, app(SourceConsumer::class)->consume($link, true));
        $this->assertNull($link->fresh()->source_station_id);
        $this->assertNull($link->fresh()->transaction_id);
        $earlyReads = in_array($missing, ['station', 'authorization'], true) ? 0 : 1;
        $this->assertSame($earlyReads, $transactionReads);
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $remove = null;

        $this->assertSame(1, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame('charging', DB::table('charging_sessions')->value('status'));
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertSame($earlyReads + 1, $transactionReads);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $remove = $missing;
        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('A started session silently accepted a vanished source identity.');
        } catch (RuntimeException $e) {
            $expected = in_array($missing, ['station', 'authorization'], true)
                ? 'identity disappeared'
                : 'transaction missing or incomplete';
            $this->assertStringContainsString($expected, $e->getMessage());
        }
        $laterReads = in_array($missing, ['station', 'authorization'], true) ? 0 : 1;
        $this->assertSame($earlyReads + 1 + $laterReads, $transactionReads);
        $this->assertSame(0, $link->fresh()->source_meter_cursor);
        $this->assertSame(8, $link->fresh()->source_station_id);
        $this->assertDatabaseCount('csms_inbox_events', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);

        $remove = null;
        $ended = true;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id,
            'status' => 'stopped',
            'energy_wh' => 2500,
        ]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertDatabaseCount('finance_invoices', 1);
    }

    #[DataProvider('protocolVersions')]
    public function test_ignored_diagnostic_clock_cannot_regress_across_source_pages(string $protocol): void
    {
        $link = $this->link($protocol);
        $at = now()->toImmutable()->startOfSecond();
        $bad = true;
        $readCursors = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $at, &$bad, &$readCursors) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => 8, 'protocol' => $protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'diagnostic-clock-cursor',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $at->addSeconds(6)->toIso8601String(),
                    'isActive' => false,
                    'totalKwh' => '2.500',
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = (int) ($request['variables']['after'] ?? -1);
                $readCursors[] = $after;
                $diagnostic = fn (int $id, int $seconds) => [
                    'id' => $id,
                    'timestamp' => $at->addSeconds($seconds)->toIso8601String(),
                    'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]],
                ];
                $energy = fn (int $id, int $seconds, string $context, string $value) => [
                    'id' => $id,
                    'timestamp' => $at->addSeconds($seconds)->toIso8601String(),
                    'sampledValue' => [[
                        'measurand' => 'Energy.Active.Import.Register',
                        'context' => $context,
                        'value' => $value,
                        'unitOfMeasure' => ['unit' => 'Wh'],
                    ]],
                ];
                $rows = match ($after) {
                    0 => [$energy(1, 1, 'Sample.Periodic', '2500'), $diagnostic(2, 3)],
                    2 => [$diagnostic(3, $bad ? 2 : 4), $energy(4, 5, 'Transaction.End', '3500')],
                    default => $this->fail('Unexpected source cursor after diagnostic chronology check.'),
                };

                return Http::response(['data' => ['MeterValues' => $rows]]);
            }

            $this->fail('A read-only source observer attempted another network operation.');
        });

        $this->assertSame(2, app(SourceConsumer::class)->consume($link, true));
        $this->assertSame(2, $link->fresh()->source_meter_cursor);
        $this->assertTrue($link->fresh()->source_meter_cursor_at->equalTo($at->addSeconds(3)));
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 0);

        try {
            app(SourceConsumer::class)->consume($link->fresh(), true);
            $this->fail('Ignored-only source row timestamp regression across pages was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('chronology regressed', $exception->getMessage());
        }
        $this->assertSame(2, $link->fresh()->source_meter_cursor);
        $this->assertTrue($link->fresh()->source_meter_cursor_at->equalTo($at->addSeconds(3)));
        $this->assertDatabaseCount('csms_inbox_events', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 1);

        $bad = false;
        $this->assertSame(1, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame([0, 2, 2], $readCursors);
        $this->assertSame(4, $link->fresh()->source_meter_cursor);
        $this->assertTrue($link->fresh()->source_meter_cursor_at->equalTo($at->addSeconds(5)));
        $this->assertDatabaseHas('charging_sessions', [
            'id' => $link->charging_session_id,
            'energy_wh' => 2500,
            'status' => 'stopped',
        ]);
        $this->assertDatabaseCount('csms_inbox_events', 3);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
    }

    public function test_meter_units_are_exact_and_unsupported_precision_is_not_rounded(): void
    {
        $this->assertSame(2500, SourceConsumer::wh('2.500000', 'kWh'));
        $this->assertSame(2500, SourceConsumer::wh('2500.0', 'Wh'));
        $this->assertSame(2500, SourceConsumer::wh(2500, 'Wh'));
        foreach ([true, false, ['2500'], 2.5, 3500.0] as $invalid) {
            try {
                SourceConsumer::wh($invalid, 'Wh');
                $this->fail('Coerced non-exact source meter type was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([[3.5, 'kWh', 0], ['0.5', 'Wh', 0], ['1.0001', 'kWh', 0], ['1', 'MWh', 0], ['1', 'Wh', 3], ['1e3', 'Wh', 0]] as $args) {
            try {
                SourceConsumer::wh(...$args);
                $this->fail('Unsupported meter was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
