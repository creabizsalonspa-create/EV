<?php

namespace App\Http\Controllers;

use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\CsmsGraphqlTransport;
use App\Services\Charging\FaultMonitor;
use App\Services\Charging\MaintenanceActor;
use App\Services\Charging\OperationsCredentials;
use App\Services\Charging\SourceStatusTime;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Safe local reset controls. A transport receipt never proves charger execution. */
class ChargerResetController extends Controller
{
    public function scope(Request $r, VendorCompany $company, VendorCharger $charger, bool $write = false): object
    {
        app(MaintenanceActor::class)->check($r->user());
        app(MaintenanceActor::class)->checkBearer($r->user());
        app(VendorAccess::class)->require($r->user(), $company, 'inventory.read');
        if ($write) {
            abort_unless($r->user()->role === 'admin' || $company->owner_id === $r->user()->id, 404);
        }
        abort_unless($charger->company_id === $company->id && $charger->source === 'simulator', 404);
        $site = VendorSite::findOrFail($charger->site_id);
        $station = Station::findOrFail($site->station_id);
        abort_unless($site->company_id === $company->id && $station->vendor_id === $company->owner_id && $station->source === 'simulator', 409);
        $rows = DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->limit(2)->get();
        abort_unless($rows->count() === 1, 409, 'One canonical simulator binding is required.');
        $b = $rows->first();
        abort_unless($b->station_id === $station->id && $b->protocol === $charger->ocpp_protocol && in_array($b->protocol, ['1.6', '2.0.1'], true) && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $b->identifier), 409);

        return $b;
    }

    /** Shared maintenance guard; caller holds the station lock in a transaction. */
    public function requireCurrentScope(Request $r, VendorCompany $company, VendorCharger $charger, object $expected): void
    {
        $currentCompany = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
        $currentCharger = VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
        $current = $this->scope($r, $currentCompany, $currentCharger, true);
        abort_unless($current->id === $expected->id
            && $current->station_id === $expected->station_id
            && $current->vendor_charger_id === $expected->vendor_charger_id
            && $current->identifier === $expected->identifier
            && $current->protocol === $expected->protocol, 409,
            'Reset source mapping changed; retain the operation for review.');
        app(VendorAccess::class)->operational($currentCompany);
    }

    /** No legacy backfill: current mapping cannot prove historical command lineage. */
    public function requireOperationGeneration(object $operation, object $binding): void
    {
        abort_unless(is_string($operation->source_generation ?? null)
            && hash_equals($operation->source_generation, FaultMonitor::generation($binding)), 409,
            'Maintenance source generation is unverified or changed; retain the hold.');
    }

    private function enabled(): void
    {
        abort_unless(config('csms.safe_reset_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']) && strlen((string) config('csms.graphql_secret')) >= 32, 503, 'Local reset adapter is disabled.');
    }

    /** Recheck local holds after external reads, immediately before a maintenance write. */
    public function requireLocalIdle(object $b): void
    {
        abort_if(SessionLink::where('binding_id', $b->id)->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->exists(), 409, 'An unresolved charging session owns this charger.');
        abort_if(Reservation::where('station_id', $b->station_id)->where('status', 'confirmed')->where('starts_at', '<', now()->addMinutes(5))->where('starts_at', '>', now()->subMinutes(30))->exists(), 409, 'A current or imminent reservation owns this station.');
        // Unresolved commands without session links also own this station.
        abort_if(DB::table('charger_commands')->where('station_id', $b->station_id)
            ->whereIn('status', ['pending', 'dispatching', 'submitted', 'outcome_unknown'])
            ->exists(), 409, 'An unresolved charging command owns this station.');
    }

    public function idle(object $b, ?string $after = null): int
    {
        $this->requireLocalIdle($b);
        $url = app(CsmsGraphqlTransport::class)->requireApproved();
        $key = (string) config('csms.graphql_secret');
        $requireAuthority = function () use ($url, $key): void {
            abort_unless(strlen($key) >= 32
                && app(CsmsGraphqlTransport::class)->requireApproved() === $url
                && hash_equals($key, (string) config('csms.graphql_secret'))
                && config('csms.session_bridge_enabled')
                && app()->environment(['local', 'testing']), 503,
                'Idle source authority changed during inspection.');
        };
        $requireAuthority();
        try {
            $r = Http::timeout(8)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])->post($url, ['query' => 'query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol} }', 'variables' => ['name' => $b->identifier]]);
            $requireAuthority();
            $rows = $r->json('data.ChargingStations');
            abort_unless($r->successful() && ! $r->json('errors') && is_array($rows) && array_is_list($rows) && count($rows) === 1 &&
                is_array($rows[0]) && is_int($rows[0]['id'] ?? null) && $rows[0]['id'] > 0 &&
                ($rows[0]['isOnline'] ?? null) === true &&
                in_array($rows[0]['protocol'] ?? null, [$b->protocol, 'ocpp'.$b->protocol], true), 409, 'A current typed online source is required.');
            $r = Http::timeout(8)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])->post($url, ['query' => 'query($station:Int!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},isActive:{_eq:true}},limit:1){id} Connectors(where:{tenantId:{_eq:1},stationId:{_eq:$station}},limit:101){id} StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:1000){connectorDatabaseId connectorStatus errorCode timestamp}}', 'variables' => ['station' => $rows[0]['id']]]);
            $requireAuthority();
            $d = $r->json('data');
            abort_unless($r->successful() && ! $r->json('errors') && is_array($d) &&
                ($d['Transactions'] ?? null) === [] &&
                is_array($d['Connectors'] ?? null) && array_is_list($d['Connectors']) &&
                count($d['Connectors']) > 0 && count($d['Connectors']) <= 100 &&
                is_array($d['StatusNotifications'] ?? null) && array_is_list($d['StatusNotifications']) &&
                count($d['StatusNotifications']) <= 1000, 409, 'Complete idle source evidence is required.');
            $known = [];
            foreach ($d['Connectors'] as $connector) {
                abort_unless(is_array($connector) && is_int($connector['id'] ?? null) &&
                    $connector['id'] > 0 && ! isset($known[$connector['id']]), 409, 'Malformed or duplicate source connector.');
                $known[$connector['id']] = true;
            }
            $latest = [];
            $lastStatusTime = null;
            $latestStatusTime = [];
            // GraphQL orders source observations by descending durable ID.
            // Validate every bounded returned row before trusting the newest
            // item; an object-shaped or string-coerced projection is not proof
            // that a reset will leave charger capacity unoccupied.
            foreach ($d['StatusNotifications'] as $row) {
                abort_unless(is_array($row) && is_int($row['connectorDatabaseId'] ?? null) &&
                    $row['connectorDatabaseId'] > 0 &&
                    is_string($row['connectorStatus'] ?? null) &&
                    is_string($row['timestamp'] ?? null) &&
                    (! isset($row['errorCode']) || (is_string($row['errorCode']) && strlen($row['errorCode']) <= 80)), 409, 'Malformed source connector status.');
                abort_unless(isset($known[$row['connectorDatabaseId']]), 409,
                    'Source status references an unknown connector; manual review is required.');
                $statusAt = SourceStatusTime::parse($row['timestamp']);
                // Descending source event IDs must never carry increasing
                // timestamps. Otherwise an earlier-clock Available report
                // could hide an otherwise newer connector fault.
                abort_unless($lastStatusTime === null || ! $statusAt->gt($lastStatusTime),
                    409, 'Source status chronology requires manual review.');
                $lastStatusTime = $statusAt;
                $connectorId = $row['connectorDatabaseId'];
                if (isset($latest[$connectorId])) {
                    abort_unless(! $statusAt->equalTo($latestStatusTime[$connectorId]) ||
                        ($row['connectorStatus'] === $latest[$connectorId]['connectorStatus'] &&
                            ($row['errorCode'] ?? null) === ($latest[$connectorId]['errorCode'] ?? null)),
                        409, 'Conflicting same-time source status requires manual review.');
                } else {
                    $latest[$connectorId] = $row;
                    $latestStatusTime[$connectorId] = $statusAt;
                }
            }
            foreach ($d['Connectors'] as $connector) {
                $status = $latest[$connector['id']] ?? null;
                // OCPP 1.6 requires an explicit NoError receipt; a missing code
                // is unknown, not idle. The 2.0.1 status contract may omit it.
                $error = $status['errorCode'] ?? null;
                $noFault = $b->protocol === '1.6' ? $error === 'NoError' : in_array($error, [null, 'NoError'], true);
                abort_unless($status && $status['connectorStatus'] === 'Available' && $noFault, 409, 'Every source connector must report fault-free Available.');
                $at = SourceStatusTime::parse($status['timestamp']);
                abort_unless($at->gte(now()->subMinutes(2)) && $at->lte(now()) && ($after === null || $at->gt(CarbonImmutable::parse($after))), 409, 'Fresh connector evidence after the operation is required.');
            }
            // The source reads can take seconds. Do not reuse a local
            // idle decision if a charging hold appeared during observation.
            $this->requireLocalIdle($b);
            $requireAuthority();

            return $rows[0]['id'];
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable) {
            abort(503, 'Authenticated source is unavailable.');
        }
    }

    public function index(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $b = $this->scope($r, $company, $charger);
        $page = DB::table('charger_reset_operations')->where('binding_id', $b->id)->select('id', 'reset_type', 'status', 'attempted_at', 'reviewed_at', 'created_at')->orderByDesc('id')->paginate(25)->toArray();

        return response()->json($page + ['can_manage' => $r->user()->role === 'admin' || $company->owner_id === $r->user()->id, 'adapter_enabled' => (bool) config('csms.safe_reset_enabled'), 'physical_execution_verified' => false])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->enabled();
        $b = $this->scope($r, $company, $charger, true);
        $v = $r->validate(['reason' => 'required|string|min:10|max:2000', 'idempotency_key' => 'required|string|max:100']);
        $hash = hash('sha256', json_encode([$b->id, $v['reason']], JSON_THROW_ON_ERROR));
        $operation = DB::transaction(function () use ($r, $company, $charger, $b, $v, $hash) {
            Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->scope($r, $company, $charger, true);
            app(VendorAccess::class)->operational($company);
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('charger_reset_operations')->where('actor_id', $r->user()->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Request identity payload mismatch.');

                return ['row' => $old, 'created' => false];
            }
            abort_if(DB::table('charger_reset_operations')->where('binding_id', $b->id)->whereNull('reviewed_at')->exists(), 409, 'Review the previous operation before another reset.');
            abort_if(DB::table('charger_firmware_operations')->where('binding_id', $b->id)->whereNull('reviewed_at')->exists(), 409, 'Review the signed firmware operation before a reset.');
            $this->idle($b);
            $this->enabled();
            $this->requireCurrentScope($r, $company, $charger, $b);
            $id = DB::table('charger_reset_operations')->insertGetId(['binding_id' => $b->id, 'source_generation' => FaultMonitor::generation($b), 'actor_id' => $r->user()->id, 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'reason' => Crypt::encryptString($v['reason']), 'reset_type' => $b->protocol === '1.6' ? 'Soft' : 'OnIdle', 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'charger.reset_requested', 'subject' => (string) $id, 'created_at' => now()]);

            return ['row' => DB::table('charger_reset_operations')->find($id), 'created' => true];
        });
        $row = $operation['row'];
        // Claim once before any network write. A crash leaves an inspectable hold.
        // HTTP retries only return the original outcome and never dispatch again.
        if ($operation['created'] && DB::table('charger_reset_operations')->where('id', $row->id)->where('status', 'requested')->update(['status' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()])) {
            $state = 'outcome_unknown';
            try {
                $state = DB::transaction(function () use ($r, $company, $charger, $b, $row) {
                    Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
                    $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $current = DB::table('charger_reset_operations')->where('id', $row->id)->lockForUpdate()->first();
                    abort_unless($current && $current->status === 'dispatching' && $current->reviewed_at === null, 409, 'Operation is no longer dispatchable.');
                    $this->requireCurrentScope($r, $company, $charger, $b);
                    $this->requireOperationGeneration($current, $b);
                    $this->enabled();
                    $sourceUrl = app(CsmsGraphqlTransport::class)->requireApproved();
                    $sourceKey = (string) config('csms.graphql_secret');
                    $sourceStation = $this->idle($b);
                    $operationsBase = config('csms.operations_base_url');
                    $auth = app(OperationsCredentials::class)->token($operationsBase);
                    $this->enabled();
                    abort_unless(config('csms.operations_base_url') === $operationsBase
                        && app(CsmsGraphqlTransport::class)->requireApproved() === $sourceUrl
                        && hash_equals($sourceKey, (string) config('csms.graphql_secret')), 409,
                        'Source or operations authority changed before reset dispatch.');
                    $this->requireCurrentScope($r, $company, $charger, $b);
                    $this->requireLocalIdle($b);
                    abort_unless(is_string($auth) && $auth !== '', 503);
                    // Token acquisition is external I/O: the previously idle
                    // charger can become occupied, faulted or reassigned.
                    // Recheck the entire typed source inventory, not only the
                    // local holds, before sending the single reset request.
                    $freshSourceStation = $this->idle($b);
                    abort_unless($freshSourceStation === $sourceStation, 409,
                        'Reset source station changed during authorization.');
                    $this->enabled();
                    abort_unless(config('csms.operations_base_url') === $operationsBase
                        && app(CsmsGraphqlTransport::class)->requireApproved() === $sourceUrl
                        && hash_equals($sourceKey, (string) config('csms.graphql_secret')), 409,
                        'Source or operations authority changed during final idle review.');
                    $this->requireCurrentScope($r, $company, $charger, $b);
                    $this->requireOperationGeneration($current, $b);
                    $this->requireLocalIdle($b);
                    $reply = Http::timeout(10)->withoutRedirecting()->withToken($auth)->post($operationsBase.'/ocpp/'.$b->protocol.'/configuration/reset?'.http_build_query(['identifier' => $b->identifier, 'tenantId' => 1]), ['type' => $row->reset_type]);

                    return $reply->successful() && $reply->json() === [['success' => true]] ? 'transport_submitted' : 'outcome_unknown';
                });
            } catch (Throwable) { /* Keep uncertain admission quarantined; never retry. */
            }
            DB::table('charger_reset_operations')->where('id', $row->id)->where('status', 'dispatching')->whereNull('reviewed_at')->update(['status' => $state, 'updated_at' => now()]);
        }

        return response()->json(['id' => $row->id, 'status' => DB::table('charger_reset_operations')->where('id', $row->id)->value('status'), 'physical_execution_verified' => false, 'capacity_released' => false], $operation['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $r, VendorCompany $company, VendorCharger $charger, int $operation)
    {
        $this->enabled();
        $b = $this->scope($r, $company, $charger, true);
        $v = $r->validate(['note' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($r, $company, $charger, $operation, $b, $v) {
            Station::whereKey($b->station_id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->scope($r, $company, $charger, true);
            app(VendorAccess::class)->operational($company);
            $row = DB::table('charger_reset_operations')->where('id', $operation)->where('binding_id', $b->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if (! $row->reviewed_at) {
                $this->requireCurrentScope($r, $company, $charger, $b);
                $this->requireOperationGeneration($row, $b);
                $attempt = $row->attempted_at ?? $row->created_at;
                $wait = in_array($row->status, ['requested', 'dispatching'], true) ? 60 : 20;
                abort_unless(now()->gte(CarbonImmutable::parse($attempt)->addSeconds($wait)), 409, 'Wait for dispatch completion before source review.');
                $this->idle($b, $attempt);
                $this->enabled();
                $this->requireCurrentScope($r, $company, $charger, $b);
                DB::table('charger_reset_operations')->where('id', $row->id)->update(['reviewed_by' => $r->user()->id, 'review_note' => Crypt::encryptString($v['note']), 'reviewed_at' => now(), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'charger.reset_source_reviewed', 'subject' => (string) $row->id, 'created_at' => now()]);
            }

            return response()->json(['reviewed' => true, 'physical_execution_verified' => false, 'charging_sessions_closed' => false])->header('Cache-Control', 'private, no-store');
        });
    }
}
