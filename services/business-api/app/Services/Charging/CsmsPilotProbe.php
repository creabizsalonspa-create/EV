<?php

namespace App\Services\Charging;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Operator-only, local simulator source-contract inspection.
 * No command, credential issuance, migration, or hardware acceptance.
 */
final class CsmsPilotProbe
{
    private function binding(int $id): object
    {
        $binding = DB::table('csms_bindings')->where('id', $id)->first();
        abort_unless($binding && $binding->vendor_charger_id !== null &&
            in_array($binding->protocol, ['1.6', '2.0.1'], true) &&
            is_string($binding->identifier) &&
            preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $binding->identifier), 409,
            'One canonical simulator source is required.');
        $charger = VendorCharger::findOrFail($binding->vendor_charger_id);
        $site = VendorSite::findOrFail($charger->site_id);
        $company = VendorCompany::findOrFail($charger->company_id);
        $station = Station::findOrFail($site->station_id);
        abort_unless($binding->station_id === $station->id &&
            $site->company_id === $company->id &&
            $company->owner_id === $station->vendor_id &&
            $company->country === $station->country &&
            $site->country === $station->country &&
            $company->status === 'approved' &&
            app(VendorAccess::class)->licenseValid($company) &&
            $charger->company_id === $company->id &&
            $charger->source === 'simulator' &&
            $station->source === 'simulator' &&
            $charger->ocpp_protocol === $binding->protocol &&
            User::whereKey($company->owner_id)->where('role', 'vendor')
                ->where('account_status', 'active')->exists() &&
            DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->count() === 1 &&
            DB::table('csms_bindings')->where('identifier', $binding->identifier)->count() === 1, 409,
            'Canonical charging ownership requires operator review.');

        return $binding;
    }

    /** Bind all reads to the configuration that passed preflight. */
    private function requireSource(string $url, string $key, string $inboxKey): void
    {
        abort_unless(app()->environment(['local', 'testing'])
            && config('csms.simulator_enabled') && config('csms.session_bridge_enabled')
            && config('csms.graphql_url') === $url
            && hash_equals($key, (string) config('csms.graphql_secret'))
            && hash_equals($inboxKey, (string) config('csms.event_secret')), 503,
            'Pilot source authority changed during inspection.');
    }

    private function graph(string $url, string $key, string $inboxKey, string $query, array $variables): array
    {
        $this->requireSource($url, $key, $inboxKey);
        try {
            $reply = Http::timeout(10)->withoutRedirecting()
                ->withHeaders(['x-hasura-admin-secret' => $key])
                ->post($url, compact('query', 'variables'));
            $data = $reply->json('data');
        } catch (Throwable) {
            abort(503, 'Pilot source is unavailable.');
        }
        $this->requireSource($url, $key, $inboxKey);
        abort_unless($reply->successful() && ! $reply->json('errors') && is_array($data), 503,
            'Pilot source evidence is unavailable.');

        return $data;
    }

    public function inspect(int $bindingId): array
    {
        // A pilot probe is read-only but must never reuse the local simulator
        // service credential on an unapproved or plaintext external endpoint.
        abort_unless(app()->environment(['local', 'testing']) &&
            app(CsmsPreflight::class)->report()['software_configuration_ready'], 503,
            'Verified local simulator source configuration is required.');
        $binding = $this->binding($bindingId);
        $charger = VendorCharger::findOrFail($binding->vendor_charger_id);
        $manufacturer = $charger->manufacturer;
        $model = $charger->model;
        $url = (string) config('csms.graphql_url');
        $key = (string) config('csms.graphql_secret');
        $inboxKey = (string) config('csms.event_secret');
        $stationQuery = 'query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol chargePointVendor chargePointModel firmwareVersion latestOcppMessageTimestamp}}';
        $stations = $this->graph($url, $key, $inboxKey, $stationQuery, [
            'name' => $binding->identifier,
        ])['ChargingStations'] ?? null;
        abort_unless(is_array($stations) && array_is_list($stations) &&
            count($stations) === 1 && is_array($stations[0]) &&
            is_int($stations[0]['id'] ?? null) && $stations[0]['id'] > 0 &&
            is_bool($stations[0]['isOnline'] ?? null) &&
            in_array($stations[0]['protocol'] ?? null, [$binding->protocol, 'ocpp'.$binding->protocol], true) &&
            ($stations[0]['chargePointVendor'] ?? null) === $manufacturer &&
            ($stations[0]['chargePointModel'] ?? null) === $model &&
            is_string($stations[0]['firmwareVersion'] ?? null) &&
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._+:-]{0,79}$/D', $stations[0]['firmwareVersion']), 409,
            'A unique typed CSMS station identity is required.');
        $station = $stations[0];
        $last = $station['latestOcppMessageTimestamp'] ?? null;
        $lastSeen = $last === null ? null : SourceStatusTime::parse($last);
        $recent = $lastSeen !== null && $lastSeen->gte(now()->subMinutes(5));

        $inventory = $this->graph($url, $key, $inboxKey,
            'query($station:Int!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},isActive:{_eq:true}},limit:2){id} Connectors(where:{tenantId:{_eq:1},stationId:{_eq:$station}},limit:101){id} StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:1001){id connectorDatabaseId evseId connectorId connectorStatus errorCode timestamp}}',
            ['station' => $station['id']]
        );
        $active = $inventory['Transactions'] ?? null;
        $connectors = $inventory['Connectors'] ?? null;
        $events = $inventory['StatusNotifications'] ?? null;
        abort_unless(is_array($active) && array_is_list($active) && count($active) <= 1 &&
            is_array($connectors) && array_is_list($connectors) &&
            count($connectors) >= 1 && count($connectors) <= 100 &&
            is_array($events) && array_is_list($events) && count($events) <= 1000, 409,
            'Bounded source inventory and transaction evidence is required.');
        foreach ($active as $transaction) {
            abort_unless(is_array($transaction) && is_int($transaction['id'] ?? null) &&
                $transaction['id'] > 0, 409, 'Invalid active source transaction.');
        }
        $known = [];
        foreach ($connectors as $connector) {
            abort_unless(is_array($connector) && is_int($connector['id'] ?? null) &&
                $connector['id'] > 0 && ! isset($known[$connector['id']]), 409,
                'Duplicate or malformed CSMS connector.');
            $known[$connector['id']] = true;
        }
        $latest = [];
        $priorId = PHP_INT_MAX;
        $priorTime = null;
        foreach ($events as $event) {
            abort_unless(is_array($event) && is_int($event['id'] ?? null) &&
                $event['id'] > 0 && $event['id'] < $priorId &&
                is_int($event['connectorDatabaseId'] ?? null) &&
                isset($known[$event['connectorDatabaseId']]) &&
                is_int($event['evseId'] ?? null) &&
                ($binding->protocol === '1.6' ? $event['evseId'] === 0 : $event['evseId'] > 0) &&
                is_int($event['connectorId'] ?? null) &&
                $event['connectorId'] > 0 &&
                in_array($event['connectorStatus'] ?? null,
                    $binding->protocol === '1.6'
                        ? ['Available', 'Preparing', 'Charging', 'SuspendedEVSE',
                            'SuspendedEV', 'Finishing', 'Reserved', 'Unavailable', 'Faulted']
                        : ['Available', 'Occupied', 'Reserved', 'Unavailable', 'Faulted'], true) &&
                (! isset($event['errorCode']) || (is_string($event['errorCode']) && strlen($event['errorCode']) <= 80)), 409,
                'Contradictory or unknown source connector event.');
            $at = SourceStatusTime::parse($event['timestamp'] ?? null);
            abort_unless($priorTime === null || ! $at->gt($priorTime), 409,
                'Source event chronology requires inspection.');
            $priorId = $event['id'];
            $priorTime = $at;
            $connectorId = $event['connectorDatabaseId'];
            $sourceTarget = $event['evseId'].':'.$event['connectorId'];
            if (! isset($latest[$connectorId])) {
                $latest[$connectorId] = [
                    'status' => $event['connectorStatus'],
                    'error_code' => $event['errorCode'] ?? null,
                    'observed_at' => $at,
                    'target' => $sourceTarget,
                ];
            } else {
                // The same durable connector ID must not change its OCPP
                // EVSE/connector target across the bounded source history.
                abort_unless($latest[$connectorId]['target'] === $sourceTarget, 409,
                    'Source connector target changed; review lineage.');
                if ($at->equalTo($latest[$connectorId]['observed_at'])) {
                    abort_unless($event['connectorStatus'] === $latest[$connectorId]['status'] &&
                        ($event['errorCode'] ?? null) === $latest[$connectorId]['error_code'], 409,
                        'Conflicting same-time connector evidence requires inspection.');
                }
            }
        }
        $recentConnectorCount = 0;
        $idleConnectorCount = 0;
        foreach (array_keys($known) as $connectorId) {
            $entry = $latest[$connectorId] ?? null;
            if ($entry && $entry['observed_at']->gte(now()->subMinutes(5))) {
                $recentConnectorCount++;
                // OCPP 1.6 StatusNotification requires an explicit
                // NoError receipt. Missing errorCode cannot certify idle,
                // even if the source labels the connector Available.
                $noFault = $binding->protocol === '1.6'
                    ? $entry['error_code'] === 'NoError'
                    : in_array($entry['error_code'], [null, 'NoError'], true);
                if ($entry['status'] === 'Available' && $noFault) {
                    $idleConnectorCount++;
                }
            }
        }
        // A CSMS station may disconnect or change firmware while a bounded
        // connector inventory query is in flight. Re-read the exact station,
        // then re-check current local ownership; neither read alone is a
        // durable physical proof or permission to issue device commands.
        $afterRows = $this->graph($url, $key, $inboxKey, $stationQuery, [
            'name' => $binding->identifier,
        ])['ChargingStations'] ?? null;
        abort_unless(is_array($afterRows) && array_is_list($afterRows) &&
            count($afterRows) === 1 && is_array($afterRows[0]) &&
            ($afterRows[0]['id'] ?? null) === $station['id'] &&
            is_bool($afterRows[0]['isOnline'] ?? null) &&
            ($afterRows[0]['protocol'] ?? null) === $station['protocol'] &&
            ($afterRows[0]['chargePointVendor'] ?? null) === $manufacturer &&
            ($afterRows[0]['chargePointModel'] ?? null) === $model &&
            ($afterRows[0]['firmwareVersion'] ?? null) === $station['firmwareVersion'] &&
            is_string($afterRows[0]['latestOcppMessageTimestamp'] ?? null), 409,
            'CSMS station identity changed while reading source evidence.');
        $afterSeen = SourceStatusTime::parse($afterRows[0]['latestOcppMessageTimestamp']);
        abort_unless($lastSeen === null || ! $afterSeen->lt($lastSeen), 409,
            'CSMS station source message clock regressed.');
        $stationOnline = $station['isOnline'] && $afterRows[0]['isOnline'];
        $recent = $recent && $afterSeen->gte(now()->subMinutes(5));

        // The source may be disconnected or stale; that is a truthful blocked
        // probe result, never an authorization to reset, start or deliver energy.
        $current = $this->binding($bindingId);
        $currentCharger = VendorCharger::findOrFail($binding->vendor_charger_id);
        abort_unless($currentCharger->manufacturer === $manufacturer &&
            $currentCharger->model === $model &&
            hash_equals(FaultMonitor::generation($binding), FaultMonitor::generation($current)), 409,
            'Charger source generation changed during observation.');

        // A source connector count is not a physical topology proof, but
        // accepting a different number of locally mapped plugs could present
        // another charger's source state as if it belonged to this owner.
        $maps = DB::table('charging_connector_mappings as mapping')
            ->join('charging_evses as evse', 'evse.id', '=', 'mapping.evse_id')
            ->join('vendor_connectors as connector', 'connector.id', '=', 'mapping.connector_id')
            ->where('mapping.station_id', $binding->station_id)
            ->where('evse.binding_id', $binding->id)
            ->where('evse.mode', 'csms')
            ->where('evse.charger_id', $charger->id)
            ->where('connector.charger_id', $charger->id)
            ->get([
                'evse.source_evse_number',
                'mapping.source_connector_number',
                'connector.maintenance_blocked',
            ]);
        $ownedCount = $maps->count();
        $totalLocalMappings = DB::table('charging_connector_mappings')
            ->where('station_id', $binding->station_id)->count();
        $seenTargets = [];
        foreach ($maps as $map) {
            abort_unless(is_int($map->source_evse_number) &&
                ($binding->protocol === '1.6' ? $map->source_evse_number === 0 : $map->source_evse_number > 0) &&
                is_int($map->source_connector_number) && $map->source_connector_number > 0 &&
                ! (bool) $map->maintenance_blocked, 409,
                'Owned connector mapping or maintenance state requires review.');
            $target = $map->source_evse_number.':'.$map->source_connector_number;
            abort_unless(! isset($seenTargets[$target]), 409,
                'Duplicate OCPP connector target requires review.');
            $seenTargets[$target] = true;
        }
        $localTopologyMatchesSourceCount = $ownedCount === count($known) &&
            $totalLocalMappings === count($known);
        $observedTargets = [];
        foreach ($latest as $entry) {
            abort_unless(! isset($observedTargets[$entry['target']]), 409,
                'Multiple source connectors claim the same OCPP target.');
            $observedTargets[$entry['target']] = true;
        }
        $sourceConnectorTargetsMatchLocal = $localTopologyMatchesSourceCount &&
            count($observedTargets) === count($seenTargets) &&
            array_diff_key($seenTargets, $observedTargets) === [];
        // A source-only snapshot cannot override durable local uncertainty.
        $localHold = DB::table('csms_session_links')->where('binding_id', $bindingId)
            ->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->exists() ||
            DB::table('charger_reset_operations')->where('binding_id', $bindingId)
                ->whereNull('reviewed_at')->exists() ||
            DB::table('charger_firmware_operations')->where('binding_id', $bindingId)
                ->whereNull('reviewed_at')->exists() ||
            DB::table('charger_commands')->where('station_id', $binding->station_id)
                ->whereIn('status', ['pending', 'dispatching', 'submitted', 'outcome_unknown'])->exists() ||
            DB::table('reservations')->where('station_id', $binding->station_id)
                ->where('status', 'confirmed')
                ->where('starts_at', '<', now()->addMinutes(5))
                ->where('starts_at', '>', now()->subMinutes(30))->exists();
        $this->requireSource($url, $key, $inboxKey);
        $covered = $recentConnectorCount === count($known);
        $idle = $idleConnectorCount === count($known) && count($active) === 0;
        $observed = $stationOnline && $recent && $covered && $idle &&
            $sourceConnectorTargetsMatchLocal && ! $localHold;

        return [
            'binding_id' => $bindingId,
            'protocol' => $binding->protocol,
            'observed_source_firmware' => $station['firmwareVersion'],
            'status' => $observed ? 'simulated_source_observed' : 'requires_source_review',
            'source_online' => $stationOnline,
            'source_identity_rechecked_after_inventory' => true,
            'latest_message_at' => $afterSeen->toISOString(),
            'recent_message' => $recent,
            'connector_count' => count($known),
            'connectors_with_recent_status' => $recentConnectorCount,
            'all_source_connectors_recent' => $covered,
            'idle_connectors_with_recent_safe_status' => $idleConnectorCount,
            'source_idle_candidate' => $idle,
            'source_connector_count_matches_local_topology' => $localTopologyMatchesSourceCount,
            'source_connector_targets_match_local_topology' => $sourceConnectorTargetsMatchLocal,
            'pending_local_charging_or_maintenance_review' => (bool) $localHold,
            'physical_topology_verified' => false,
            'active_source_transaction_count' => count($active),
            'software_source_observed' => (bool) $observed,
            'evidence' => 'SIMULATED_CSMS_READ_ONLY',
            'device_commands_sent' => false,
            'database_mutations' => false,
            'hardware_pilot_approved' => false,
            'physical_hardware_accepted' => false,
            'production_accepted' => false,
        ];
    }
}
