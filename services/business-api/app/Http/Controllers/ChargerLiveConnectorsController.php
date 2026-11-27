<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Services\Charging\ChargerSourceRegistration;
use App\Services\Charging\CsmsGraphqlTransport;
use App\Services\Charging\DirectConnectorIdentity;
use App\Services\Charging\DirectConnectorReviewCoverage;
use App\Services\Charging\DirectSourceAuthority;
use App\Services\Charging\DirectSourceRegistrationEpoch;
use App\Services\Charging\MaintenanceActor;
use App\Services\Charging\RemoteSessionAuthorization;
use App\Services\Charging\SourceStatusTime;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, scoped source testimony for simulator or registered direct inventory.
 * A CSMS observation never grants a physical connector or remote command.
 */
final class ChargerLiveConnectorsController extends Controller
{
    private function sourceAuthority(bool $direct): array
    {
        $endpoint = config('csms.graphql_url');
        $secret = config('csms.graphql_secret');
        abort_unless(($direct
                ? config('csms.registered_live_monitoring_enabled') === true && is_string($endpoint)
                    && parse_url($endpoint, PHP_URL_SCHEME) === 'https'
                : app()->environment(['local', 'testing']) && config('csms.session_bridge_enabled')) &&
            app(CsmsGraphqlTransport::class)->approved($endpoint) &&
            is_string($secret) && strlen($secret) >= 32, 503,
            'Authenticated source connector monitoring is disabled.');

        return [$endpoint, $secret, $direct ? app(DirectSourceAuthority::class)->requireCurrent() : null];
    }

    /** Registration proves an exact upstream record, not permission to control it. */
    public function registeredScope(Request $r, VendorCompany $company, VendorCharger $charger): object
    {
        return DB::transaction(function () use ($r, $company, $charger) {
            app(MaintenanceActor::class)->checkBearer($r->user());
            $registration = app(ChargerSourceRegistration::class);
            [$company, $charger, $station] = $registration->scope($r, $company, $charger);
            app(VendorAccess::class)->require($r->user(), $company, 'inventory.read');
            app(VendorAccess::class)->operational($company);
            abort_unless(User::whereKey($company->owner_id)->where('role', 'vendor')
                ->where('account_status', 'active')->lockForUpdate()->first(), 409, 'Current active owner required.');
            abort_unless($registration->verified($company, $charger), 409,
                'Current verified direct registration is required.');
            $receipt = DB::table('charger_source_registrations')->where('charger_id', $charger->id)
                ->where('state', 'registered')->lockForUpdate()->first();
            abort_unless($receipt && is_int($receipt->source_station_id) && $receipt->source_station_id > 0, 409);
            $binding = DB::table('csms_bindings')->where('id', $receipt->binding_id)->lockForUpdate()->first();
            abort_unless($binding && $binding->station_id === $station->id &&
                $binding->vendor_charger_id === $charger->id && $binding->protocol === $charger->ocpp_protocol, 409);
            // Reconstruct the original offline registration receipt, not the
            // mutable live station state. GraphQL's selected field order is
            // the order used by the registration adapter when hashing it.
            $expectedReceipt = hash('sha256', json_encode([
                'id' => $receipt->source_station_id, 'tenantId' => 1,
                'ocppConnectionName' => $binding->identifier, 'protocol' => 'ocpp'.$binding->protocol,
                'isOnline' => false, 'chargePointVendor' => $charger->manufacturer,
                'chargePointModel' => $charger->model,
            ], JSON_THROW_ON_ERROR));
            abort_unless(is_string($receipt->source_receipt_hash)
                && hash_equals($expectedReceipt, $receipt->source_receipt_hash), 409,
                'Original source registration receipt requires reconciliation.');
            $authority = app(DirectSourceAuthority::class)->requireCurrent();
            app(DirectSourceRegistrationEpoch::class)->requireCurrent($receipt, $authority);
            // Pin durable registration provenance, not an arbitrary same-name row.
            $binding->registration_receipt = [$receipt->id, $receipt->profile_id, $receipt->source_station_id,
                $receipt->source_generation, $receipt->source_receipt_hash, $receipt->verified_at, $receipt->payload_hash,
                $authority];

            return $binding;
        }, 3);
    }

    private function reviewCoverage(Request $r, VendorCompany $company, VendorCharger $charger, object $binding, array $sources, bool $complete): array
    {
        return DB::transaction(function () use ($r, $company, $charger, $binding, $sources, $complete) {
            $fresh = $this->registeredScope($r, $company, $charger);
            abort_unless($fresh->id === $binding->id && $fresh->identifier === $binding->identifier
                && $fresh->protocol === $binding->protocol && $fresh->station_id === $binding->station_id
                && $fresh->registration_receipt === $binding->registration_receipt, 409,
                'Direct registration changed before review coverage.');
            $inventory = VendorConnector::where('charger_id', $charger->id)->orderBy('id')
                ->limit(101)->lockForUpdate()->get()->map(fn ($connector) => [
                    'id' => (int) $connector->id,
                    'fingerprint' => DirectConnectorIdentity::fingerprint($connector),
                ])->all();
            $epoch = $fresh->registration_receipt[7][0];
            $registration = $fresh->registration_receipt[0];
            $fingerprint = hash('sha256', json_encode([$fresh->id, $fresh->station_id, $fresh->vendor_charger_id,
                $fresh->identifier, $fresh->protocol, $fresh->registration_receipt], JSON_THROW_ON_ERROR));
            $receipts = DB::table('direct_connector_review_receipts')->where('charger_id', $charger->id)
                ->where('authority_epoch', $epoch)->orderBy('id')->limit(101)->lockForUpdate()->get()
                ->map(fn ($row) => [
                    'connector_id' => $row->connector_id,
                    'registration_id' => $row->registration_id,
                    'authority_epoch' => $row->authority_epoch,
                    'target' => $row->source_evse_number.':'.$row->source_connector_number,
                    'database_id' => $row->source_connector_database_id,
                    'event_id' => $row->source_event_id,
                    'fingerprint' => $row->registration_fingerprint,
                    'connector_fingerprint' => $row->connector_fingerprint,
                    'observed_at' => CarbonImmutable::parse($row->source_observed_at, 'UTC'),
                ])->all();
            app(MaintenanceActor::class)->checkBearer($r->user());

            return app(DirectConnectorReviewCoverage::class)->evaluate($inventory, $receipts, $sources,
                $registration, $epoch, $fingerprint, $complete);
        }, 3);
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
            'query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id tenantId ocppConnectionName isOnline protocol chargePointVendor chargePointModel firmwareVersion latestOcppMessageTimestamp}}',
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
        if (isset($binding->registration_receipt)) {
            abort_unless(($row['tenantId'] ?? null) === 1
                && ($row['ocppConnectionName'] ?? null) === $binding->identifier
                && $row['id'] === $binding->registration_receipt[2], 409,
                'Source station does not match the registered tenant and identity.');
        }
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
        return $this->inspect($r, $company, $charger, $guard, $source);
    }

    /** Internal callers can retain exact database/event IDs; public GET cannot request them. */
    public function inspect(
        Request $r,
        VendorCompany $company,
        VendorCharger $charger,
        ChargerResetController $guard,
        RemoteSessionAuthorization $source,
        bool $internalReview = false
    ) {
        $direct = $charger->source === 'direct_unverified';
        abort_unless(! $internalReview || $direct, 409);
        $authority = $this->sourceAuthority($direct);
        $graph = function (string $query, array $variables) use ($authority, $source, $direct): array {
            abort_unless($this->sourceAuthority($direct) === $authority, 503, 'Connector source authority changed during inspection.');
            $result = $source->graph($query, $variables);
            abort_unless($this->sourceAuthority($direct) === $authority, 503, 'Connector source authority changed during inspection.');

            return $result;
        };
        $binding = $direct ? $this->registeredScope($r, $company, $charger)
            : $guard->scope($r, $company, $charger);
        app(MaintenanceActor::class)->checkBearer($r->user());
        // Direct inventory remains unverified. Inspect the upstream topology
        // without inventing local mappings or expanding booking admission.
        $local = $direct ? [] : $this->inventory($binding, $charger);
        $station = $this->sourceStation($graph, $binding, $charger);
        abort_unless(! $direct || $station['id'] === $binding->registration_receipt[2], 409,
            'Source station differs from registered receipt.');

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
                is_int($event['connectorId'] ?? null) && $event['connectorId'] > 0 && $event['connectorId'] <= 100 &&
                array_key_exists('evseId', $event) &&
                ($binding->protocol === '1.6'
                    ? in_array($event['evseId'], [null, 0], true)
                    : (is_int($event['evseId']) && $event['evseId'] > 0 && $event['evseId'] <= 1000)) &&
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
                'source_event_id' => $event['id'],
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
        $freshCompany = VendorCompany::findOrFail($company->id);
        $fresh = $direct ? $this->registeredScope($r, $freshCompany, $freshCharger)
            : $guard->scope($r, $freshCompany, $freshCharger);
        abort_unless($freshCharger->manufacturer === $charger->manufacturer &&
            $freshCharger->model === $charger->model &&
            $freshCharger->site_id === $charger->site_id &&
            $fresh->id === $binding->id &&
            $fresh->identifier === $binding->identifier &&
            $fresh->protocol === $binding->protocol &&
            $fresh->station_id === $binding->station_id &&
            ($direct ? $fresh->registration_receipt === $binding->registration_receipt
                : $this->inventory($fresh, $freshCharger) === $local),
            409, 'Owned local connector topology changed during inspection.');

        abort_unless($this->sourceAuthority($direct) === $authority, 409, 'Source authority changed before projection.');
        app(MaintenanceActor::class)->checkBearer($r->user());
        $localTargets = array_fill_keys(array_column($local, 'target'), true);
        $sourceComplete = count($sourceTargets) === count($known);
        $sourceTopologyMatches = ! $direct && count($local) === count($known) &&
            $sourceComplete && array_diff_key($localTargets, $sourceTargets) === [];
        if ($direct) {
            // A known upstream component with no event has no defensible
            // OCPP target. Never fabricate one; mark the whole topology incomplete.
            ksort($sourceTargets, SORT_NATURAL);
            foreach ($sourceTargets as $target => $sourceId) {
                [$evseNumber, $connectorNumber] = array_map('intval', explode(':', $target));
                $local[] = ['target' => $target, 'mapping_id' => null,
                    'source_evse_number' => $evseNumber, 'source_connector_number' => $connectorNumber,
                    'operator_maintenance_blocked' => null];
            }
        }
        $complete = $direct ? $sourceComplete : $sourceTopologyMatches;
        $stationRecent = $station['isOnline'] && $after['isOnline'] &&
            $station['heartbeat']->gte(now()->subMinutes(5)) &&
            $after['heartbeat']->gte(now()->subMinutes(5));
        $rows = [];
        $reviewSources = [];
        foreach ($local as $row) {
            $event = $latest[$row['target']] ?? null;
            $eventRecent = $event && $event['observed_at']->gte(now()->subMinutes(5));
            // An OCPP1.6 Available status without an explicit NoError
            // receipt cannot establish a healthy charger; stale/missing
            // fault evidence remains unknown, not a green source status.
            $missingFaultReceipt = $binding->protocol === '1.6' && $event
                && $event['reported_status'] === 'Available'
                && in_array($event['error_code'], [null, ''], true);
            $trusted = $stationRecent && $complete && $eventRecent
                && ! $missingFaultReceipt;
            $rows[] = [
                'mapping_id' => $row['mapping_id'],
                'source_evse_number' => $row['source_evse_number'],
                'source_connector_number' => $row['source_connector_number'],
                'operator_maintenance_blocked' => $row['operator_maintenance_blocked'],
                'state' => $trusted ? 'recent' : ($event && $complete && ! $missingFaultReceipt ? 'stale' : 'unknown'),
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
            if ($direct) {
                $reviewSources[] = ['target' => $row['target'], 'database_id' => $sourceTargets[$row['target']],
                    'event_id' => $event['source_event_id'] ?? null, 'observed_at' => $event['observed_at'] ?? null,
                    'state' => $rows[array_key_last($rows)]['state']];
            }
            if ($internalReview) {
                $rows[array_key_last($rows)] += [
                    'source_connector_database_id' => $sourceTargets[$row['target']],
                    'source_event_id' => $event['source_event_id'] ?? null,
                ];
            }
        }

        $reviewCoverage = $direct ? $this->reviewCoverage($r, $company, $charger, $binding, $reviewSources, $complete) : null;
        abort_unless($this->sourceAuthority($direct) === $authority, 409, 'Source authority changed before response.');
        app(MaintenanceActor::class)->checkBearer($r->user());

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
            'evidence' => $direct ? 'REGISTERED_DIRECT_SOURCE' : 'SIMULATED_CSMS_SOURCE',
            'source_topology_complete' => $sourceComplete,
            'local_mapping_verified' => ! $direct && $sourceTopologyMatches,
            'physical_availability_verified' => false,
            'remote_control_authorized' => false,
            'commands_sent' => false,
            'connectors' => $rows,
        ] + ($direct ? ['review_coverage' => $reviewCoverage] : []) + ($internalReview ? [
            'internal_registration_fingerprint' => hash('sha256', json_encode([
                $binding->id, $binding->station_id, $binding->vendor_charger_id,
                $binding->identifier, $binding->protocol, $binding->registration_receipt,
            ], JSON_THROW_ON_ERROR)),
        ] : []))->header('Cache-Control', 'private, no-store');
    }
}
