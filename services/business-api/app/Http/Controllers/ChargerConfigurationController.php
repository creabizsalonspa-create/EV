<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\ConfigurationProbe;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChargerConfigurationController extends Controller
{
    private function scope(Request $r, VendorCompany $company, VendorCharger $charger, bool $write = false): object
    {
        $actor = User::findOrFail($r->user()->id);
        $r->setUserResolver(fn () => $actor);

        return app(ChargerResetController::class)->scope($r, $company->fresh(), $charger->fresh(), $write);
    }

    private function present(object $row): array
    {
        return ['id' => $row->id, 'protocol' => $row->protocol, 'status' => $row->status, 'attempted_at' => $row->attempted_at, 'observed_at' => $row->observed_at,
            'created_at' => $row->created_at, 'snapshot' => $row->snapshot_ciphertext ? json_decode(Crypt::decryptString($row->snapshot_ciphertext), true, flags: JSON_THROW_ON_ERROR) : null,
            'evidence' => 'SIMULATED_SOURCE', 'settings_changed' => false, 'physical_charging_verified' => false];
    }

    public function index(Request $r, VendorCompany $company, VendorCharger $charger, ConfigurationProbe $source)
    {
        $b = $this->scope($r, $company, $charger);
        $page = DB::table('charger_configuration_reads')->where('binding_id', $b->id)->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->present($row))->toArray();

        $enabled = false;
        try {
            $source->enabled(true);
            app(VendorAccess::class)->operational($company->fresh());
            $enabled = true;
        } catch (Throwable) {
        }

        return response()->json($page + ['can_request' => ($r->user()->role === 'admin' || $company->owner_id === $r->user()->id) && $enabled, 'settings_writes_enabled' => false])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, VendorCompany $company, VendorCharger $charger, ConfigurationProbe $source)
    {
        $source->enabled(true);
        $b = $this->scope($r, $company, $charger, true);
        $v = $r->validate(['idempotency_key' => 'required|string|max:100', 'reason' => 'required|string|min:10|max:500']);
        $hash = hash('sha256', json_encode([$b->id, $b->identifier, $b->protocol, $v['reason']], JSON_THROW_ON_ERROR));
        $op = DB::transaction(function () use ($r, $company, $charger, $b, $v, $hash, $source) {
            $current = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->scope($r, $current, $charger, true);
            app(VendorAccess::class)->operational($current);
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('charger_configuration_reads')->where('actor_id', $r->user()->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409);

                return ['row' => $old, 'created' => false];
            }
            abort_if(DB::table('charger_configuration_reads')->where('binding_id', $b->id)->whereIn('status', ['requested', 'dispatching', 'transport_submitted', 'outcome_unknown'])->where('created_at', '>', now()->subMinute())->exists(), 409, 'Observe the previous device reply before requesting another read.');
            $id = DB::table('charger_configuration_reads')->insertGetId(['binding_id' => $b->id, 'actor_id' => $r->user()->id, 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'source_identifier' => $b->identifier, 'protocol' => $b->protocol, 'status' => 'requested', 'request_payload' => json_encode($source->payload($b->protocol), JSON_THROW_ON_ERROR), 'reason' => Crypt::encryptString($v['reason']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'charger.configuration_read_requested', 'subject' => (string) $id, 'created_at' => now()]);

            return ['row' => DB::table('charger_configuration_reads')->find($id), 'created' => true];
        }, 3);
        $row = $op['row'];
        if ($op['created'] && DB::table('charger_configuration_reads')->where('id', $row->id)->where('status', 'requested')->update(['status' => 'dispatching', 'attempted_at' => now()->utc()->format('Y-m-d H:i:s.u'), 'updated_at' => now()])) {
            $sent = false;
            $status = 'not_dispatched';
            try {
                $source->station($b);
                $token = $source->serviceToken();
                $current = $this->scope($r, $company, $charger, true);
                app(VendorAccess::class)->operational($company->fresh());
                abort_unless($current->id === $b->id && $current->identifier === $b->identifier && $current->protocol === $b->protocol, 409);
                $source->enabled(true);
                $sent = true;
                $status = $source->submit($b, $token, json_decode($row->request_payload, true, flags: JSON_THROW_ON_ERROR)) ? 'transport_submitted' : 'outcome_unknown';
            } catch (Throwable) {
                $status = $sent ? 'outcome_unknown' : 'not_dispatched';
            }
            DB::table('charger_configuration_reads')->where('id', $row->id)->where('status', 'dispatching')->update(['status' => $status, 'updated_at' => now()]);
        }
        $this->scope($r, $company, $charger, true);

        return response()->json($this->present(DB::table('charger_configuration_reads')->find($row->id)), $op['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function observe(Request $r, VendorCompany $company, VendorCharger $charger, int $operation, ConfigurationProbe $source)
    {
        $source->enabled();
        $b = $this->scope($r, $company, $charger);
        $op = DB::table('charger_configuration_reads')->where('id', $operation)->where('binding_id', $b->id)->first();
        abort_unless($op && $op->protocol === $b->protocol && $op->source_identifier === $b->identifier, 404);
        if (! $op->observed_at && $op->attempted_at && $op->status !== 'not_dispatched') {
            $station = $source->station($b, false);
            $action = $b->protocol === '1.6' ? 'GetConfiguration' : 'GetVariables';
            $requests = $source->graph('query($station:Int!,$action:String!,$after:timestamptz!,$until:timestamptz!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"csms"},type:{_eq:2},action:{_eq:$action},createdAt:{_gte:$after,_lte:$until},payload:{_eq:$match}},limit:2){id createdAt}}', ['station' => $station, 'action' => $action, 'after' => CarbonImmutable::parse($op->attempted_at)->toISOString(), 'until' => CarbonImmutable::parse($op->attempted_at)->addSeconds(30)->toISOString(), 'match' => $op->request_payload ? json_decode($op->request_payload, true, flags: JSON_THROW_ON_ERROR) : $source->payload($b->protocol)])['OCPPMessages'];
            if (count($requests) === 1) {
                $replies = $source->graph('query($station:Int!,$request:Int!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},requestMessageId:{_eq:$request},type:{_in:[3,4]}},limit:2){id type payload createdAt}}', ['station' => $station, 'request' => $requests[0]['id']])['OCPPMessages'];
                if (count($replies) === 1 && ($replies[0]['type'] === 4 || is_array($replies[0]['payload']))) {
                    $reply = $replies[0];
                    $state = $reply['type'] === 3 ? 'source_observed' : 'device_rejected';
                    $observedAt = CarbonImmutable::parse($reply['createdAt'])->utc();
                    if ($observedAt->lessThan(CarbonImmutable::parse($requests[0]['createdAt']))) {
                        abort(409);
                    }
                    try {
                        $snapshot = $reply['type'] === 3 ? $source->snapshot($b->protocol, $reply['payload']) : [];
                    } catch (Throwable) {
                        $state = 'invalid_source_response';
                        $snapshot = [];
                    }
                    DB::transaction(function () use ($r, $company, $charger, $op, $b, $requests, $reply, $snapshot, $state, $observedAt) {
                        $current = $this->scope($r, $company, $charger);
                        abort_unless($current->id === $b->id && $current->protocol === $op->protocol && $current->identifier === $op->source_identifier, 409);
                        $locked = DB::table('charger_configuration_reads')->where('id', $op->id)->lockForUpdate()->firstOrFail();
                        if (! $locked->observed_at) {
                            DB::table('charger_configuration_reads')->where('id', $op->id)->update(['status' => $state, 'snapshot_ciphertext' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 'source_payload_hash' => hash('sha256', json_encode($reply['payload'], JSON_THROW_ON_ERROR)), 'source_request_id' => $requests[0]['id'], 'source_reply_id' => $reply['id'], 'observed_at' => $observedAt->format('Y-m-d H:i:s.u'), 'updated_at' => now()]);
                        }
                    }, 3);
                }
            }
        }
        $this->scope($r, $company, $charger);

        return response()->json($this->present(DB::table('charger_configuration_reads')->find($op->id)), 200)->header('Cache-Control', 'private, no-store');
    }
}
