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

    public static function inboxModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('inboxModes')]
    public function test_source_authentication_signed_delivery_restart_cursor_and_final_reconciliation(bool $localInbox): void
    {
        $link = $this->link();
        $at = now()->toImmutable()->startOfSecond();
        $tx = ['id' => 10, 'transactionId' => 'fixture-transaction', 'remoteStartId' => $link->start_command_id, 'meterStart' => '1.000000', 'startTime' => $at->toIso8601String(), 'endTime' => null, 'isActive' => true, 'totalKwh' => null];
        $rows = [
            ['id' => 1, 'timestamp' => $at->toIso8601String(), 'sampledValue' => [['measurand' => 'Voltage', 'value' => 230]]],
            ['id' => 2, 'timestamp' => $at->addSecond()->toIso8601String(), 'sampledValue' => [['measurand' => 'Energy.Active.Import.Register', 'value' => '2500', 'context' => 'Sample.Periodic', 'unitOfMeasure' => ['unit' => 'Wh']]]],
            ['id' => 3, 'timestamp' => $at->addSeconds(2)->toIso8601String(), 'sampledValue' => [['measurand' => 'Energy.Active.Import.Register', 'value' => '3.500', 'context' => 'Transaction.End', 'unitOfMeasure' => ['unit' => 'kWh']]]],
        ];
        $loseEndResponse = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$tx, $rows, $link, &$loseEndResponse, $localInbox) {
            if ($request->url() === 'http://127.0.0.1:8095/v1/graphql') {
                $this->assertSame(self::KEY, $request->header('x-hasura-admin-secret')[0]);
                $query = $request['query'];
                if (str_contains($query, 'ChargingStations')) {
                    $this->assertSame('EV-SIM-'.$link->charging_session_id, $request['variables']['tag']);

                    return Http::response(['data' => ['ChargingStations' => [['id' => 8, 'protocol' => '2.0.1']], 'Authorizations' => [['id' => 9]]]]);
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
            // Retry the exact persisted source identity after a lost response.
            $this->assertSame(1, $consumer->consume($link->fresh(), $localInbox));
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
        $final = false;
        $transactionReads = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($link, $protocol, $at, &$sourceId, &$final, &$transactionReads) {
            $query = $request['query'];
            if (str_contains($query, 'ChargingStations')) {
                return Http::response(['data' => [
                    'ChargingStations' => [['id' => $sourceId, 'protocol' => 'ocpp'.$protocol]],
                    'Authorizations' => [['id' => 9]],
                ]]);
            }
            if (str_contains($query, 'Transactions')) {
                $transactionReads++;
                if ($remove === 'transaction') {
                    return Http::response(['data' => ['Transactions' => []]]);
                }

                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10, 'transactionId' => $protocol === '1.6' ? '101' : 'source-station-identity',
                    'remoteStartId' => $link->start_command_id, 'meterStart' => '1.000',
                    'startTime' => $at->toIso8601String(),
                    'endTime' => $final ? $at->addSeconds(2)->toIso8601String() : null,
                    'isActive' => ! $final, 'totalKwh' => $final ? '2.500' : null,
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

        // Independent confirmation of the original source identity permits
        // finalization without replaying an uncertain charger command.
        $sourceId = 8;
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

    public static function invalidMeterPages(): array
    {
        $cases = [];
        foreach (['1.6', '2.0.1'] as $protocol) {
            foreach (['duplicate_id', 'regressing_id', 'zero_id', 'oversized_id', 'string_id', 'non_absolute_time', 'future_non_energy', 'missing_samples', 'non_array_sample', 'non_list_samples', 'non_list_page', 'oversized_page', 'missing_page', 'nonenergy_before_start', 'nonenergy_after_end', 'later_ambiguous_register', 'later_invalid_unit', 'later_after_end'] as $kind) {
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
            'later_after_end' => [$row(1), array_replace($valid[1], ['timestamp' => $at->addSeconds(3)->toIso8601String()])],
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

                return Http::response(['data' => ['MeterValues' => $after > 0 ? [] : $rows]]);
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
                    101 => [],
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
        $this->assertSame([0, 100, 101], $requested);
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
            'timestamp' => $at->toIso8601String(),
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
        Http::fake(function (Request $request) use ($link, $protocol, $at, $rows, &$pagesRequested) {
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

        $this->assertSame(0, app(SourceConsumer::class)->consume($link->fresh(), true));
        $this->assertSame([0, 100, 200, 250], $pagesRequested);
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

                return Http::response(['data' => ['Transactions' => [[
                    'id' => 10,
                    'transactionId' => $protocol === '1.6' ? '101' : 'disappeared-source-fixture',
                    'remoteStartId' => $link->start_command_id,
                    'meterStart' => $remove === 'meter_start' ? null : '1.000',
                    'startTime' => $remove === 'start_time' ? null : $at->toIso8601String(),
                    'endTime' => $ended ? $at->addSeconds(2)->toIso8601String() : null,
                    'isActive' => ! $ended,
                    'totalKwh' => $ended ? '2.500' : null,
                ]]]]);
            }
            if (str_contains($query, 'MeterValues')) {
                $after = (int) ($request['variables']['after'] ?? -1);

                return Http::response(['data' => ['MeterValues' =>
                    $ended && $after === 0 ? [[
                        'id' => 1, 'timestamp' => $at->addSeconds(2)->toIso8601String(),
                        'sampledValue' => [[
                            'measurand' => 'Energy.Active.Import.Register',
                            'value' => '3500',
                            'context' => 'Transaction.End',
                            'unitOfMeasure' => ['unit' => 'Wh'],
                        ]],
                    ]] : [],
                ]]);
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
