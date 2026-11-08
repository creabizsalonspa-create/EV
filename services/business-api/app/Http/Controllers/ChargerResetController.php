<?php

namespace App\Http\Controllers;

use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
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

    private function enabled(): void
    {
        abort_unless(config('csms.safe_reset_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']) && strlen((string) config('csms.graphql_secret')) >= 32, 503, 'Local reset adapter is disabled.');
    }

    public function idle(object $b, ?string $after = null): void
    {
        abort_if(SessionLink::where('binding_id', $b->id)->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->exists(), 409, 'An unresolved charging session owns this charger.');
        abort_if(Reservation::where('station_id', $b->station_id)->where('status', 'confirmed')->where('starts_at', '<', now()->addMinutes(5))->where('starts_at', '>', now()->subMinutes(30))->exists(), 409, 'A current or imminent reservation owns this station.');
        try {
            $r = Http::timeout(8)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), ['query' => 'query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol} }', 'variables' => ['name' => $b->identifier]]);
            $rows = $r->json('data.ChargingStations');
            abort_unless($r->successful() && ! $r->json('errors') && is_array($rows) && count($rows) === 1 && $rows[0]['isOnline'] === true && in_array($rows[0]['protocol'], [$b->protocol, 'ocpp'.$b->protocol], true), 409, 'A current online source is required.');
            $r = Http::timeout(8)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), ['query' => 'query($station:Int!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},isActive:{_eq:true}},limit:1){id} Connectors(where:{tenantId:{_eq:1},stationId:{_eq:$station}},limit:101){id} StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:1000){connectorDatabaseId connectorStatus timestamp}}', 'variables' => ['station' => $rows[0]['id']]]);
            $d = $r->json('data');
            abort_unless($r->successful() && ! $r->json('errors') && is_array($d) && $d['Transactions'] === [] && count($d['Connectors']) > 0 && count($d['Connectors']) <= 100, 409, 'Idle source evidence is required.');
            $latest = [];
            foreach ($d['StatusNotifications'] as $row) {
                if (! isset($latest[$row['connectorDatabaseId']])) {
                    $latest[$row['connectorDatabaseId']] = $row;
                }
            }
            foreach ($d['Connectors'] as $connector) {
                $status = $latest[$connector['id']] ?? null;
                abort_unless($status && $status['connectorStatus'] === 'Available', 409, 'Every source connector must report Available.');
                $at = SourceStatusTime::parse($status['timestamp'] ?? null);
                abort_unless($at->gte(now()->subMinutes(2)) && $at->lte(now()) && ($after === null || $at->gt(CarbonImmutable::parse($after))), 409, 'Fresh connector evidence after the operation is required.');
            }
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
            $id = DB::table('charger_reset_operations')->insertGetId(['binding_id' => $b->id, 'actor_id' => $r->user()->id, 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'reason' => Crypt::encryptString($v['reason']), 'reset_type' => $b->protocol === '1.6' ? 'Soft' : 'OnIdle', 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
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
                    $this->scope($r, $company, $charger, true);
                    app(VendorAccess::class)->operational($company);
                    $this->enabled();
                    $this->idle($b);
                    $auth = Http::timeout(5)->withoutRedirecting()->post(config('csms.operations_base_url').'/dev/token', ['roles' => ['admin'], 'tenantId' => 1])->throw()->json('accessToken');
                    abort_unless(is_string($auth) && $auth !== '', 503);
                    $reply = Http::timeout(10)->withoutRedirecting()->withToken($auth)->post(config('csms.operations_base_url').'/ocpp/'.$b->protocol.'/configuration/reset?'.http_build_query(['identifier' => $b->identifier, 'tenantId' => 1]), ['type' => $row->reset_type]);

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
                $attempt = $row->attempted_at ?? $row->created_at;
                $wait = in_array($row->status, ['requested', 'dispatching'], true) ? 60 : 20;
                abort_unless(now()->gte(CarbonImmutable::parse($attempt)->addSeconds($wait)), 409, 'Wait for dispatch completion before source review.');
                $this->idle($b, $attempt);
                DB::table('charger_reset_operations')->where('id', $row->id)->update(['reviewed_by' => $r->user()->id, 'review_note' => Crypt::encryptString($v['note']), 'reviewed_at' => now(), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'charger.reset_source_reviewed', 'subject' => (string) $row->id, 'created_at' => now()]);
            }

            return response()->json(['reviewed' => true, 'physical_execution_verified' => false, 'charging_sessions_closed' => false])->header('Cache-Control', 'private, no-store');
        });
    }
}
