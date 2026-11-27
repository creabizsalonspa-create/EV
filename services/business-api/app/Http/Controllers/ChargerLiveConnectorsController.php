<?php

namespace App\Http\Controllers;

use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\CsmsGraphqlTransport;
use App\Services\Charging\MaintenanceActor;
use App\Services\Charging\RemoteSessionAuthorization;
use App\Services\Charging\SourceStatusTime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, scoped software source status for one owned simulator charger.
 * A CSMS observation never grants a physical connector or remote command.
 */
final class ChargerLiveConnectorsController extends Controller
{
    private function sourceAuthority(): array
    {
        $endpoint = config('csms.graphql_url');
        $secret = config('csms.graphql_secret');
        abort_unless(app()->environment(['local', 'testing']) &&
            config('csms.session_bridge_enabled') &&
            app(CsmsGraphqlTransport::class)->approved($endpoint) &&
            is_string($secret) && strlen($secret) >= 32, 503,
            'Local source connector monitoring is disabled.');

        return [$endpoint, $secret];
    }

    private function inventory(object $binding, VendorCharger $charger): array
    {
        $rows = DB::table('charging_connector_mappings as mapping')
            ->join('charging_evses as evse', 'evse.id', '=', 'mapping.evse_id')
            ->join('vendor_connectors as connector', 'connector.id', '=', 'mapping.connector_id')
            ->where('mapping.station_id', $binding->station_id)
            ->where('evse.binding_id', $binding->id)
            ->where('evse.charger_id', $charger->id)
            ->where('evse.mode', 'csms')
            ->where('connector.charger_id', $charger->id)
            ->orderBy('mapping.id')->limit(101)
            ->get(['mapping.id', 'mapping.source_connector_number',
                'mapping.source_target_key', 'evse.source_evse_number',
                'connector.maintenance_blocked']);
        abort_unless($rows->isNotEmpty() && $rows->count() <= 100, 409, 'No complete local connector topology.');

        $targets = [];
        $projection = [];
        foreach ($rows as $row) {
            $evse = $row->source_evse_number;
            $number = $row->source_connector_number;
            abort_unless(is_int($evse) && is_int($number) && $number > 0 &&
                ($binding->protocol === '1.6' ? $evse === 0 : $evse > 0), 409,
                'Local connector mapping requires review.');
            $target = $evse.':'.$number;
            $signedTargetKey = hash('sha256', $binding->id.':'.$target);
            abort_unless(is_string($row->source_target_key) &&
                hash_equals($signedTargetKey, $row->source_target_key), 409,
                'Local connector source binding requires review.');
            abort_unless(! isset($targets[$target]), 409, 'Duplicated local connector mapping.');
            $targets[$target] = true;
            $projection[] = [
                'mapping_id' => (int) $row->id,
                'source_evse_number' => $evse,
                'source_connector_number' => $number,
                'operator_maintenance_blocked' => (bool) $row->maintenance_blocked,
                'target' => $target,
            ];
        }

        return $projection;
    }

    private function sourceStation(Closure $graph, object $binding, VendorCharger $charger): array
    {
        $rows = $graph(
            'query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol chargePointVendor chargePointModel firmwareVersion latestOcppMessageTimestamp}}',
            ['name' => $binding->identifier]
        )['ChargingStations'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) === 1 &&
            is_array($rows[0]) && is_int($rows[0]['id'] ?? null) && $rows[0]['id'] > 0 &&
            is_bool($rows[0]['isOnline'] ?? null) &&
            in_array($rows[0]['protocol'] ?? null, [$binding->protocol, 'ocpp'.$binding->protocol], true) &&
            ($rows[0]['chargePointVendor'] ?? null) === $charger->manufacturer &&
            ($rows[0]['chargePointModel'] ?? null) === $charger->model &&
            is_string($rows[0]['latestOcppMessageTimestamp'] ?? null) &&
            (array_key_exists('firmwareVersion', $rows[0]) &&
                ($rows[0]['firmwareVersion'] === null ||
                    (is_string($rows[0]['firmwareVersion']) &&
                        strlen($rows[0]['firmwareVersion']) <= 100))), 409,
            'Current unique typed charger source is required.');

        $row = $rows[0];
        $row['heartbeat'] = SourceStatusTime::parse($row['latestOcppMessageTimestamp']);

        return $row;
    }

    public function __invoke(
        Request $r,
        VendorCompany $company,
        VendorCharger $charger,
        ChargerResetController $guard,
        RemoteSessionAuthorization $source
    ) {
        $authority = $this->sourceAuthority();
        $graph = function (string $query, array $variables) use ($authority, $source): array {
            abort_unless($this->sourceAuthority() === $authority, 503, 'Connector source authority changed during inspection.');
            $result = $source->graph($query, $variables);
            abort_unless($this->sourceAuthority() === $authority, 503, 'Connector source authority changed during inspection.');

            return $result;
        };
        $binding = $guard->scope($r, $company, $charger);
        app(MaintenanceActor::class)->checkBearer($r->user());
        $local = $this->inventory($binding, $charger);
        $station = $this->sourceStation($graph, $binding, $charger);

        // Query at most 100 connectors and 1000 events. Missing per-target
        // evidence remains unknown; no stale or truncated source is healthy.
        $data = $graph(
            'query($station:Int!){Connectors(where:{tenantId:{_eq:1},stationId:{_eq:$station}},limit:101){id} StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:1001){id connectorDatabaseId evseId connectorId connectorStatus errorCode timestamp}}',
            ['station' => $station['id']]
        );
        $connectors = $data['Connectors'] ?? null;
        $events = $data['StatusNotifications'] ?? null;
        abort_unless(is_array($connectors) && array_is_list($connectors) &&
            count($connectors) > 0 && count($connectors) <= 100 &&
            is_array($events) && array_is_list($events) && count($events) <= 1000, 409,
            'Bounded unique source connector evidence is required.');
        $known = [];
        foreach ($connectors as $connector) {
            abort_unless(is_array($connector) && is_int($connector['id'] ?? null) &&
                $connector['id'] > 0 && ! isset($known[$connector['id']]), 409,
                'Duplicate or untyped source connector.');
            $known[$connector['id']] = true;
        }

        $latest = [];
        $previousByTarget = [];
        $sourceTargets = [];
        $sourceConnectorTargets = [];
        $lastEvent = PHP_INT_MAX;
        // OCPP 1.6 StatusNotification and OCPP 2.0.1 StatusNotification
        // have different connector state vocabularies. A status belonging
        // only to the other protocol is not source health evidence.
        $statusValues = $binding->protocol === '1.6'
            ? ['Available', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE',
                'Finishing', 'Reserved', 'Unavailable', 'Faulted']
            : ['Available', 'Occupied', 'Reserved', 'Unavailable', 'Faulted'];
        foreach ($events as $event) {
            abort_unless(is_array($event) && is_int($event['id'] ?? null) &&
                $event['id'] > 0 && $event['id'] < $lastEvent &&
                is_int($event['connectorDatabaseId'] ?? null) &&
                isset($known[$event['connectorDatabaseId']]) &&
                is_int($event['connectorId'] ?? null) && $event['connectorId'] > 0 &&
                array_key_exists('evseId', $event) &&
                ($binding->protocol === '1.6'
                    ? in_array($event['evseId'], [null, 0], true)
                    : (is_int($event['evseId']) && $event['evseId'] > 0)) &&
                in_array($event['connectorStatus'] ?? null, $statusValues, true) &&
                (! isset($event['errorCode']) || (is_string($event['errorCode']) &&
                    strlen($event['errorCode']) <= 80)), 409, 'Malformed current connector source evidence.');
            $lastEvent = $event['id'];
            $target = ($binding->protocol === '1.6' ? '0' : $event['evseId']).':'.$event['connectorId'];
            $sourceId = $event['connectorDatabaseId'];
            // A durable source connector ID must not migrate between OCPP
            // targets. Similarly two source connector IDs may not claim one
            // current target.
            if (isset($sourceConnectorTargets[$sourceId])) {
                abort_unless($sourceConnectorTargets[$sourceId] === $target, 409,
                    'Source connector target changed.');
            }
            $sourceConnectorTargets[$sourceId] = $target;
            if (isset($sourceTargets[$target])) {
                abort_unless($sourceTargets[$target] === $sourceId, 409,
                    'Ambiguous source connector target.');
            }
            $sourceTargets[$target] = $sourceId;
            $at = SourceStatusTime::parse($event['timestamp'] ?? null);
            $observation = [
                'reported_status' => $event['connectorStatus'],
                'error_code' => $event['errorCode'] ?? null,
                'observed_at' => $at,
            ];
            // Rows are sorted by descending source event ID. Compare every
            // consecutive pair per target, not only against its newest row.
            // Otherwise a regression or contradiction deeper in the retained
            // history can appear to be complete, trusted source evidence.
            $previous = $previousByTarget[$target] ?? null;
            if ($previous !== null) {
                abort_unless(! $at->gt($previous['observed_at']), 409,
                    'Source connector observation clock regressed.');
                if ($at->eq($previous['observed_at'])) {
                    abort_unless($observation['reported_status'] === $previous['reported_status'] &&
                        $observation['error_code'] === $previous['error_code'], 409,
                        'Contradictory source states at the same instant.');
                }
            }
            $latest[$target] ??= $observation;
            $previousByTarget[$target] = $observation;
        }

        // The same station must still be the uniquely authenticated source
        // after the inventory read; a silent disconnect invalidates a recent
        // status despite any earlier "online" flag.
        $after = $this->sourceStation($graph, $binding, $charger);
        abort_unless($after['id'] === $station['id'] &&
            $after['protocol'] === $station['protocol'] &&
            $after['firmwareVersion'] === $station['firmwareVersion'] &&
            ! $after['heartbeat']->lt($station['heartbeat']), 409,
            'Source identity or clock changed during connector inspection.');
        $freshCharger = VendorCharger::findOrFail($charger->id);
        $fresh = $guard->scope($r, VendorCompany::findOrFail($company->id),
            $freshCharger);
        abort_unless($freshCharger->manufacturer === $charger->manufacturer &&
            $freshCharger->model === $charger->model &&
            $freshCharger->site_id === $charger->site_id &&
            $fresh->id === $binding->id &&
            $fresh->identifier === $binding->identifier &&
            $fresh->protocol === $binding->protocol &&
            $this->inventory($fresh, VendorCharger::findOrFail($charger->id)) === $local,
            409, 'Owned local connector topology changed during inspection.');

        app(MaintenanceActor::class)->checkBearer($r->user());
        $localTargets = array_fill_keys(array_column($local, 'target'), true);
        $sourceTopologyMatches = count($local) === count($known) &&
            count($sourceTargets) === count($known) &&
            array_diff_key($localTargets, $sourceTargets) === [];
        $stationRecent = $station['isOnline'] && $after['isOnline'] &&
            $station['heartbeat']->gte(now()->subMinutes(5)) &&
            $after['heartbeat']->gte(now()->subMinutes(5));
        $rows = [];
        foreach ($local as $row) {
            $event = $latest[$row['target']] ?? null;
            $eventRecent = $event && $event['observed_at']->gte(now()->subMinutes(5));
            // An OCPP1.6 Available status without an explicit NoError
            // receipt cannot establish a healthy charger; stale/missing
            // fault evidence remains unknown, not a green source status.
            $missingFaultReceipt = $binding->protocol === '1.6' && $event
                && $event['reported_status'] === 'Available'
                && in_array($event['error_code'], [null, ''], true);
            $trusted = $stationRecent && $sourceTopologyMatches && $eventRecent
                && ! $missingFaultReceipt;
            $rows[] = [
                'mapping_id' => $row['mapping_id'],
                'source_evse_number' => $row['source_evse_number'],
                'source_connector_number' => $row['source_connector_number'],
                'operator_maintenance_blocked' => $row['operator_maintenance_blocked'],
                'state' => $trusted ? 'recent' : ($event && $sourceTopologyMatches && ! $missingFaultReceipt ? 'stale' : 'unknown'),
                // An OCPP error accompanying an "Available" label must
                // never be presented as a healthy connector report.
                'reported_status' => $trusted
                    ? (in_array($event['error_code'], [null, '', 'NoError'], true)
                        ? $event['reported_status'] : 'Faulted')
                    : null,
                'error_code' => $trusted ? $event['error_code'] : null,
                'source_event_at' => $event ? $event['observed_at']->toISOString() : null,
                // Even "Available" is source testimony only, never a
                // booking, relay-closure or start authorization.
                'physical_available' => false,
                'remote_control_authorized' => false,
            ];
        }

        return response()->json([
            'company_id' => $company->id,
            'charger_id' => $charger->id,
            'protocol' => $binding->protocol,
            'source_online' => $stationRecent,
            'source_firmware_version' => $after['firmwareVersion'],
            'source_last_message_at' => $after['heartbeat']->toISOString(),
            'source_topology_matches_local' => $sourceTopologyMatches,
            'source_identity_rechecked' => true,
            'observed_at' => now()->toISOString(),
            'evidence' => 'SIMULATED_CSMS_SOURCE',
            'physical_availability_verified' => false,
            'remote_control_authorized' => false,
            'commands_sent' => false,
            'connectors' => $rows,
        ])->header('Cache-Control', 'private, no-store');
    }
}
