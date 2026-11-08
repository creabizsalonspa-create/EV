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

            return Http::response(['data' => ['Connectors' => [['id' => 7]], 'StatusNotifications' => [['evseId' => 1, 'connectorId' => 1, 'connectorStatus' => 'Available', 'timestamp' => $statusTime]]]]);
        });
        $guard = app(RiderSourceController::class);
        $this->assertFalse($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
        $statusTime = now()->subSecond()->toISOString();
        $this->assertTrue($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
        $statusTime = now()->addMinute()->toISOString();
        $this->assertFalse($guard->targetMatches($binding, $map, $evse, ['connectorId' => 7]));
    }
}
