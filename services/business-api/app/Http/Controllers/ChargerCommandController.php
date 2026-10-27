<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargerCommandController extends Controller
{
    public function bind(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'admin', 403);
        abort_unless($station->source === 'simulator', 409, 'Only simulator inventory may be bound.');
        $v = $r->validate(['identifier' => ['required', 'string', 'regex:/^ev-test-[a-zA-Z0-9-]{1,80}$/', 'unique:csms_bindings,identifier'], 'protocol' => 'required|in:1.6,2.0.1']);
        abort_if(DB::table('csms_bindings')->where('station_id', $station->id)->exists(), 409, 'Binding is already provisioned.');

        return DB::transaction(function () use ($r, $station, $v) {
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
        $v = $r->validate(['action' => 'required|in:start,stop', 'idempotency_key' => 'required|string|max:100', 'transaction_id' => 'required_if:action,stop|string|max:36']);
        $payload = $v['action'] === 'start' ? [] : ['transaction_id' => $v['transaction_id']];
        $hash = hash('sha256', json_encode([$station->id, $v['action'], $payload]));
        $old = ChargerCommand::where('actor_id', $r->user()->id)->where('idempotency_key', $v['idempotency_key'])->first();
        if ($old) {
            abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Idempotency key payload mismatch.');

            return response()->json($old);
        }

        return DB::transaction(function () use ($r, $station, $v, $payload, $hash) {
            $c = ChargerCommand::create(['actor_id' => $r->user()->id, 'station_id' => $station->id, 'action' => $v['action'], 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'payload' => $payload]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'csms.command_queued', 'subject' => (string) $c->id, 'created_at' => now()]);

            return response()->json($c, 202);
        });
    }
}
