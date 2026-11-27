<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ChargingActorConsent;
use App\Services\Charging\OcppTransactionId;
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
            $company = VendorCompany::findOrFail($charger->company_id);
            abort_unless($site->station_id === $station->id && $site->company_id === $charger->company_id
                && $company->owner_id === $station->vendor_id
                && $site->country === $station->country && $company->country === $station->country
                && $charger->source === 'simulator'
                && $charger->ocpp_protocol === $v['protocol'], 409, 'Charger binding mismatch.');
            abort_if(DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->exists(), 409, 'Charger is already provisioned.');
        } else {
            abort_if(DB::table('csms_bindings')->where('station_id', $station->id)->exists(), 409, 'Binding is already provisioned.');
        }

        return DB::transaction(function () use ($r, $station, $v) {
            $actor = app(ChargingActorConsent::class)->capture($r->user());
            abort_unless($actor->role === 'admin', 403);
            // Match source-registration's lock order (company → charger →
            // site → station) to avoid reverse-order deadlocks. A snapshot
            // supplies only the company-key hint; compare it with the pinned
            // charger row before accepting the binding.
            if (! empty($v['vendor_charger_id'])) {
                $asset = VendorCharger::whereKey($v['vendor_charger_id'])->firstOrFail();
                $company = VendorCompany::whereKey($asset->company_id)->lockForUpdate()->firstOrFail();
                $charger = VendorCharger::whereKey($v['vendor_charger_id'])->lockForUpdate()->firstOrFail();
                $site = VendorSite::whereKey($charger->site_id)->lockForUpdate()->firstOrFail();
            }
            $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            abort_unless($station->source === 'simulator', 409, 'Only simulator inventory may be bound.');
            if (! empty($v['vendor_charger_id'])) {
                abort_unless($company->id === $charger->company_id
                    && $site->station_id === $station->id
                    && $site->company_id === $charger->company_id
                    && $company->owner_id === $station->vendor_id
                    && $site->country === $station->country
                    && $company->country === $station->country
                    && $charger->source === 'simulator'
                    && $charger->ocpp_protocol === $v['protocol'], 409, 'Charger binding mismatch.');
            }
            abort_if(DB::table('csms_bindings')->when(! empty($v['vendor_charger_id']), fn ($q) => $q->where('vendor_charger_id', $v['vendor_charger_id']), fn ($q) => $q->where('station_id', $station->id))->exists(), 409, 'Binding is already provisioned.');
            $id = DB::table('csms_bindings')->insertGetId($v + ['station_id' => $station->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'csms.simulator_bound', 'subject' => (string) $station->id, 'created_at' => now()]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function index(Request $r)
    {
        return DB::transaction(function () use ($r) {
            // Materialize the private projection while current role, password,
            // status and epoch are pinned; a request model is only a snapshot.
            $actor = app(ChargingActorConsent::class)->capture($r->user());
            abort_unless(in_array($actor->role, ['admin', 'vendor'], true), 403);
            $query = ChargerCommand::query();
            if ($actor->role !== 'admin') {
                $query->whereIn('station_id', Station::where('vendor_id', $actor->id)->select('id'));
            }

            return response()->json($query->orderByDesc('id')->paginate(25))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    private function legacyInventory(Station $station): string
    {
        $bindings = DB::table('csms_bindings')->where('station_id', $station->id)->limit(2)->get(['protocol']);
        abort_unless($station->source === 'simulator' && $bindings->isNotEmpty(), 409, 'No provisioned simulator binding.');
        abort_if(ConnectorMapping::where('station_id', $station->id)->exists() || $bindings->count() !== 1,
            409, 'Use an explicitly mapped reservation and session.');
        $protocol = $bindings->first()->protocol;
        abort_unless(in_array($protocol, ['1.6', '2.0.1'], true), 409, 'Unsupported simulator binding protocol.');

        return $protocol;
    }

    private function stopIdentity(array $values, string $protocol): void
    {
        if ($values['action'] === 'stop') {
            abort_unless($protocol !== '1.6' || OcppTransactionId::valid16($values['transaction_id']),
                422, 'OCPP 1.6 stop requires a canonical signed 32-bit transaction ID.');
        }
    }

    public function create(Request $r, Station $station)
    {
        abort_unless($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id, 404);
        $protocol = $this->legacyInventory($station);
        $v = $r->validate(['action' => 'required|in:start,stop', 'idempotency_key' => 'required|string|max:100', 'transaction_id' => 'required_if:action,stop|string|max:36']);
        $this->stopIdentity($v, $protocol);
        $payload = $v['action'] === 'start' ? [] : ['transaction_id' => $v['transaction_id']];
        $hash = hash('sha256', json_encode([$station->id, $v['action'], $payload]));

        return DB::transaction(function () use ($r, $station, $v, $payload, $hash) {
            $actor = app(ChargingActorConsent::class)->capture($r->user());
            // Retain the existing actor -> station lock order, but use the
            // persisted inventory rather than a stale route-bound model.
            $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'vendor' && $station->vendor_id === $actor->id, 404);
            $this->stopIdentity($v, $this->legacyInventory($station));
            // Every replay shares the current account/inventory boundary;
            // no optimistic response may bypass consent revocation.
            $existing = ChargerCommand::where('actor_id', $actor->id)
                ->where('idempotency_key', $v['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Idempotency key payload mismatch.');

                return response()->json($existing);
            }
            $c = ChargerCommand::create(['consent_auth_epoch' => $v['action'] === 'start' ? (int) $actor->auth_epoch : null, 'actor_id' => $r->user()->id, 'station_id' => $station->id, 'action' => $v['action'], 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'payload' => $payload]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'csms.command_queued', 'subject' => (string) $c->id, 'created_at' => now()]);

            return response()->json($c, 202);
        });
    }
}
