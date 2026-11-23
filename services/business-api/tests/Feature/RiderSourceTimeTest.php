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
        $evse = new Evse(['source_evse_number' => 1]);
        $statusTime = 'now';
        Http::fake(function ($request) use ($protocol, &$statusTime) {
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [['id' => 42, 'protocol' => $protocol, 'isOnline' => true]]]]);
            }

            return Http::response(['data' => ['Connectors' => [['id' => 7]], 'StatusNotifications' => [['evseId' => 1, 'connectorId' => 1, 'connectorStatus' => 'Available', 'errorCode' => 'NoError', 'timestamp' => $statusTime]]]]);
        });
        $guard = app(RiderSourceController::class);
        $this->assertFalse($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
        $statusTime = now()->subSecond()->toISOString();
        $this->assertTrue($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
        $statusTime = now()->addMinute()->toISOString();
        $this->assertFalse($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
    }
    #[DataProvider('protocols')]
    public function test_rider_authorization_rejects_non_available_and_contradictory_fault_source_receipts(string $protocol): void
    {
        config(['csms.graphql_secret' => str_repeat('s', 64), 'csms.graphql_url' => 'https://source.test/graph']);
        Http::preventStrayRequests();
        $binding = (object) ['identifier' => 'ev-test-bounded-rider-status', 'protocol' => $protocol];
        $map = new ConnectorMapping(['source_connector_number' => 1]);
        $evse = new Evse(['source_evse_number' => 1]);
        $source = ['connectorStatus' => 'Available', 'errorCode' => 'NoError',
            'evseId' => 1, 'connectorId' => 1, 'timestamp' => now()->subSecond()->toISOString()];
        Http::fake(function ($request) use ($protocol, &$source) {
            if (str_contains($request['query'], 'ChargingStations')) {
                return Http::response(['data' => ['ChargingStations' => [
                    ['id' => 42, 'protocol' => $protocol, 'isOnline' => true],
                ]]]);
            }

            return Http::response(['data' => [
                'Connectors' => [['id' => 7]], 'StatusNotifications' => [$source],
            ]]);
        });
        $target = app(RiderSourceController::class);
        $scope = ['connectorId' => 7, 'evseId' => 1];
        $this->assertTrue($target->targetMatches($binding, $map, $evse, $scope));
        foreach (['Reserved', 'Occupied', 'Charging', 'Preparing',
            'Finishing', 'Unavailable', 'Faulted', 'Unknown', '<script>'] as $status) {
            $source['connectorStatus'] = $status;
            $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope),
                'An occupied, malformed or faulted connector cannot admit a rider.');
        }
        $source['connectorStatus'] = 'Available';
        if ($protocol === '1.6') {
            foreach (['GroundFailure', 'PowerSwitchFailure', 'OtherError', null] as $fault) {
                $source['errorCode'] = $fault;
                $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope));
            }
            $source['errorCode'] = 'NoError';
            $this->assertTrue($target->targetMatches($binding, $map, $evse, $scope));
        } else {
            $this->assertFalse($target->targetMatches($binding, $map, $evse,
                ['connectorId' => 7, 'evseId' => 2]));
            $this->assertTrue($target->targetMatches($binding, $map, $evse, $scope));
        }
        $source['connectorId'] = 2;
        $this->assertFalse($target->targetMatches($binding, $map, $evse, $scope));
        Http::assertSentCount($protocol === '1.6' ? 32 : 26);
    }

}
