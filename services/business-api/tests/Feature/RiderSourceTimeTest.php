<?php

namespace Tests\Feature;

use App\Http\Controllers\RiderSourceController;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiderSourceTimeTest extends TestCase
{
    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_source_target_requires_an_absolute_current_time_before_card_admission(string $protocol): void
    {
        config(['csms.graphql_secret' => str_repeat('s', 64), 'csms.graphql_url' => 'https://source.test/graph']);
        Http::preventStrayRequests();
        $binding = (object) ['identifier' => 'ev-test-source-clock', 'protocol' => $protocol];
        $map = new ConnectorMapping(['source_connector_number' => 1]);
        $evse = new Evse(['source_evse_number' => $protocol === '1.6' ? 0 : 1]);
        $statusTime = 'now';
        Http::fake(function ($request) use ($protocol, &$statusTime) {
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $protocol, 'isOnline' => true]]]]);
            }

            return Http::response(['data' => ['Connectors' => [['id' => 7, 'evseId' => 77]], 'StatusNotifications' => [['evseId' => $protocol === '1.6' ? 0 : 1, 'connectorId' => 1, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => $statusTime]]]]);
        });
        $guard = app(RiderSourceController::class);
        $this->assertFalse($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
        $statusTime = now()->subSecond()->toISOString();
        $this->assertTrue($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
        $statusTime = now()->addMinute()->toISOString();
        $this->assertFalse($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
    }

    #[DataProvider('protocols')]
    public function test_multi_connector_station_reads_only_requested_source_connector_identity(string $protocol): void
    {
        config(['csms.graphql_secret' => str_repeat('s', 64),
            'csms.graphql_url' => 'https://source.test/graph']);
        Http::preventStrayRequests();
        $binding = (object) [
            'identifier' => 'ev-test-multi-source-connector', 'protocol' => $protocol,
        ];
        $map = new ConnectorMapping(['source_connector_number' => 2]);
        $evseNumber = $protocol === '1.6' ? 0 : 1;
        $evse = new Evse(['source_evse_number' => $evseNumber]);
        Http::fake(function ($request) use ($protocol, $evseNumber) {
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [[
                    'id' => 42, 'protocol' => $protocol, 'isOnline' => true,
                ]]]]);
            }

            // A charger with two database connectors must not make
            // authorization ambiguous: the GraphQL source request is scoped
            // to the selected database ID before checking its latest status.
            $selected = str_contains($request['query'], 'id:{_eq:$connector}')
                && ($request['variables']['connector'] ?? null) === 8;

            return Http::response(['data' => [
                'Connectors' => $selected ? [['id' => 8, 'evseId' => 77]] : [['id' => 7, 'evseId' => 77], ['id' => 8, 'evseId' => 77]],
                'StatusNotifications' => [[
                    'evseId' => $evseNumber, 'connectorId' => 2,
                    'connectorStatus' => 'Available', 'errorCode' => 'NoError',
                    'timestamp' => now()->subSecond()->toISOString(),
                ]],
            ]]);
        });
        $source = app(RiderSourceController::class);
        $this->assertTrue($source->targetMatches($binding, $map, $evse,
            ['connectorId' => 8, 'evseId' => 77]));
        $this->assertFalse($source->targetMatches($binding, $map, $evse,
            ['connectorId' => 7, 'evseId' => 77]));
        Http::assertSentCount(4);
    }

    #[DataProvider('protocols')]
    public function test_rider_authorization_rejects_non_available_and_contradictory_fault_source_receipts(string $protocol): void
    {
        config(['csms.graphql_secret' => str_repeat('s', 64), 'csms.graphql_url' => 'https://source.test/graph']);
        Http::preventStrayRequests();
        $binding = (object) ['identifier' => 'ev-test-bounded-rider-status', 'protocol' => $protocol];
        $map = new ConnectorMapping(['source_connector_number' => 1]);
        $evse = new Evse(['source_evse_number' => $protocol === '1.6' ? 0 : 1]);
        $source = ['connectorStatus' => 'Available', 'errorCode' => 'NoError',
            'evseId' => $protocol === '1.6' ? 0 : 1, 'connectorId' => 1, 'timestamp' => now()->subSecond()->toISOString()];
        Http::fake(function ($request) use ($protocol, &$source) {
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [
                    ['id' => 42, 'protocol' => $protocol, 'isOnline' => true],
                ]]]);
            }

            return Http::response(['data' => [
                'Connectors' => [['id' => 7, 'evseId' => 77]], 'StatusNotifications' => [$source],
            ]]);
        });
        $target = app(RiderSourceController::class);
        $scope = ['connectorId' => 7, 'evseId' => 77];
        $this->assertTrue($target->targetMatches($binding, $map, $evse, $scope));
        foreach (['Reserved', 'Occupied', 'Charging', 'Preparing',
            'Finishing', 'Unavailable', 'Faulted', 'Unknown', '<script>'] as $status) {
            $source['connectorStatus'] = $status;
            $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope),
                'An occupied, malformed or faulted connector cannot admit a rider.');
        }
        $source['connectorStatus'] = 'Available';
        if ($protocol === '1.6') {
            $this->assertFalse($target->targetMatches($binding, $map, $evse,
                ['connectorId' => 7, 'evseId' => 2]));
            foreach (['GroundFailure', 'PowerSwitchFailure', 'OtherError', null] as $fault) {
                $source['errorCode'] = $fault;
                $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope));
            }
            $source['errorCode'] = 'NoError';
            $this->assertTrue($target->targetMatches($binding, $map, $evse, $scope));
        } else {
            $this->assertFalse($target->targetMatches($binding, $map, $evse,
                ['connectorId' => 7, 'evseId' => 2]));
            $source['errorCode'] = 'GroundFailure';
            $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope),
                'OCPP 2.0.1 Available accompanied by a source fault cannot grant rider access.');
            $source['errorCode'] = null;
            $this->assertTrue($target->targetMatches($binding, $map, $evse, $scope));
        }
        $source['connectorId'] = 2;
        $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope));
        $source['connectorId'] = 1;
        $source['evseId'] = $protocol === '1.6' ? 1 : 2;
        $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope));
        $source['evseId'] = $protocol === '1.6' ? 0 : 1;
        $this->assertFalse($target->targetMatches($binding, $map, $evse,
            ['connectorId' => 7, 'evseId' => $source['evseId']]));
        $this->assertTrue($target->targetMatches($binding, $map, $evse, $scope));
        Http::assertSentCount($protocol === '1.6' ? 40 : 34);
    }

    #[DataProvider('protocols')]
    public function test_existing_remote_session_source_scope_does_not_grant_new_rider_admission(string $protocol): void
    {
        config(['csms.graphql_secret' => str_repeat('s', 64), 'csms.graphql_url' => 'https://source.test/graph']);
        Http::preventStrayRequests();
        $binding = (object) ['identifier' => 'ev-test-existing-remote-target', 'protocol' => $protocol];
        $map = new ConnectorMapping(['source_connector_number' => 1]);
        $wireEvse = $protocol === '1.6' ? 0 : 1;
        $evse = new Evse(['source_evse_number' => $wireEvse]);
        $row = ['evseId' => $wireEvse, 'connectorId' => 1, 'connectorStatus' => 'Available',
            'errorCode' => $protocol === '1.6' ? 'NoError' : null, 'timestamp' => now()->subSecond()->toISOString()];
        Http::fake(function ($request) use ($protocol, &$row) {
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $protocol, 'isOnline' => true]]]]);
            }

            return Http::response(['data' => ['Connectors' => [['id' => 7, 'evseId' => 77]], 'StatusNotifications' => [$row]]]);
        });
        $guard = app(RiderSourceController::class);
        $scope = ['connectorId' => 7, 'evseId' => 77];
        $states = $protocol === '1.6'
            ? ['Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved']
            : ['Occupied', 'Reserved'];
        foreach ($states as $state) {
            $row['connectorStatus'] = $state;
            $this->assertTrue($guard->remoteSessionTargetMatches($binding, $map, $evse, $scope));
            $this->assertFalse($guard->targetMatches($binding, $map, $evse, $scope));
        }
        foreach (['Faulted', 'Unavailable', 'Unknown', $protocol === '1.6' ? 'Occupied' : 'Charging'] as $state) {
            $row['connectorStatus'] = $state;
            $this->assertFalse($guard->remoteSessionTargetMatches($binding, $map, $evse, $scope));
        }
        $row['connectorStatus'] = 'Available';
        $this->assertTrue($guard->targetMatches($binding, $map, $evse, $scope));
        $this->assertTrue($guard->remoteSessionTargetMatches($binding, $map, $evse, $scope));
        $this->assertFalse($guard->remoteSessionTargetMatches($binding, $map, $evse, ['connectorId' => 7, 'evseId' => 78]));
        $row['errorCode'] = 'GroundFailure';
        $this->assertFalse($guard->remoteSessionTargetMatches($binding, $map, $evse, $scope));
        $row['errorCode'] = $protocol === '1.6' ? 'NoError' : null;
        $row['timestamp'] = now()->subMinutes(6)->toISOString();
        $this->assertFalse($guard->remoteSessionTargetMatches($binding, $map, $evse, $scope));
    }

    public static function authorityChanges(): array
    {
        return [['1.6', 'endpoint'], ['1.6', 'secret'], ['2.0.1', 'endpoint'], ['2.0.1', 'secret']];
    }

    #[DataProvider('authorityChanges')]
    public function test_rider_source_authority_drift_never_admits_connector(string $protocol, string $change): void
    {
        $url = 'https://source.test/graph';
        $secret = str_repeat('s', 64);
        config(['csms.graphql_url' => $url, 'csms.graphql_secret' => $secret]);
        Http::preventStrayRequests();
        $binding = (object) ['identifier' => 'ev-test-rider-authority', 'protocol' => $protocol];
        $map = new ConnectorMapping(['source_connector_number' => 1]);
        $wireEvse = $protocol === '1.6' ? 0 : 1;
        $evse = new Evse(['source_evse_number' => $wireEvse]);
        Http::fake(function ($request) use ($protocol, $wireEvse, $url, $secret, $change) {
            $this->assertSame($url, $request->url());
            $this->assertSame($secret, $request->header('x-hasura-admin-secret')[0] ?? null);
            if (str_contains($request['query'], 'ChargingStations')) {
                if ($change === 'endpoint') {
                    config(['csms.graphql_url' => 'https://alternate.example.test/graph']);
                }

                return Http::response(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $protocol, 'isOnline' => true]]]]);
            }
            config(['csms.graphql_secret' => str_repeat('t', 64)]);

            return Http::response(['data' => ['Connectors' => [['id' => 7, 'evseId' => 77]],
                'StatusNotifications' => [['evseId' => $wireEvse, 'connectorId' => 1,
                    'connectorStatus' => 'Available', 'errorCode' => 'NoError',
                    'timestamp' => now()->subSecond()->toISOString()]]]]);
        });

        $this->assertFalse(app(RiderSourceController::class)->targetMatches($binding, $map, $evse, ['connectorId' => 7, 'evseId' => 77]));
        Http::assertSentCount($change === 'endpoint' ? 1 : 2);
    }
}
