<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargerCommandController extends Controller
{
    public function bind(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'admin', 403);
        abort_unless($station->source === 'simulator', 409, 'Only simulator inventory may be bound.');
        $v = $r->validate(['identifier' => ['required', 'string', 'regex:/^ev-test-[a-zA-Z0-9-]{1,80}$/', 'unique:csms_bindings,identifier'], 'protocol' => 'required|in:1.6,2.0.1', 'vendor_charger_id' => 'nullable|integer|exists:vendor_chargers,id']);
        if (! empty($v['vendor_charger_id'])) {
            $charger = VendorCharger::findOrFail($v['vendor_charger_id']);
            $site = VendorSite::findOrFail($charger->site_id);
            abort_unless($site->station_id === $station->id && $charger->source === 'simulator' && $charger->ocpp_protocol === $v['protocol'], 409, 'Charger binding mismatch.');
            abort_if(DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->exists(), 409, 'Charger is already provisioned.');
        } else {
            abort_if(DB::table('csms_bindings')->where('station_id', $station->id)->exists(), 409, 'Binding is already provisioned.');
        }

        return DB::transaction(function () use ($r, $station, $v) {
            Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('csms_bindings')->when(! empty($v['vendor_charger_id']), fn ($q) => $q->where('vendor_charger_id', $v['vendor_charger_id']), fn ($q) => $q->where('station_id', $station->id))->exists(), 409, 'Binding is already provisioned.');
            $id = DB::table('csms_bindings')->insertGetId($v + ['station_id' => $station->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'csms.simulator_bound', 'subject' => (string) $station->id, 'created_at' => now()]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function index(Request $r)
    {
        $q = ChargerCommand::query();
        if ($r->user()->role !== 'admin') {
            $q->whereIn('station_id', Station::where('vendor_id', $r->user()->id)->select('id'));
        }

        return $q->orderByDesc('id')->paginate(25);
    }

    public function create(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id, 404);
        abort_unless($station->source === 'simulator' && DB::table('csms_bindings')->where('station_id', $station->id)->exists(), 409, 'No provisioned simulator binding.');
        abort_if(ConnectorMapping::where('station_id', $station->id)->exists() || DB::table('csms_bindings')->where('station_id', $station->id)->count() !== 1, 409, 'Use an explicitly mapped reservation and session.');
        $v = $r->validate(['action' => 'required|in:start,stop', 'idempotency_key' => 'required|string|max:100', 'transaction_id' => 'required_if:action,stop|string|max:36']);
        $payload = $v['action'] === 'start' ? [] : ['transaction_id' => $v['transaction_id']];
        $hash = hash('sha256', json_encode([$station->id, $v['action'], $payload]));
        $old = ChargerCommand::where('actor_id', $r->user()->id)->where('idempotency_key', $v['idempotency_key'])->first();
        if ($old) {
            abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Idempotency key payload mismatch.');

            return response()->json($old);
        }

        return DB::transaction(function () use ($r, $station, $v, $payload, $hash) {
            Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            abort_if(ConnectorMapping::where('station_id', $station->id)->exists(), 409, 'Use a mapped reservation.');
            $c = ChargerCommand::create(['actor_id' => $r->user()->id, 'station_id' => $station->id, 'action' => $v['action'], 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'payload' => $payload]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'csms.command_queued', 'subject' => (string) $c->id, 'created_at' => now()]);

            return response()->json($c, 202);
        });
    }
}
