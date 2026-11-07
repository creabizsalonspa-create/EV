<?php

namespace App\Http\Controllers;

use App\Models\Charging\AdmissionProfile;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ConnectorTopology;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectorTopologyController extends Controller
{
    private function scope(Request $request, VendorCompany $company, VendorCharger $charger, bool $write = false): Station
    {
        app(VendorAccess::class)->require($request->user(), $company, $write ? 'inventory.write' : 'inventory.read');
        abort_unless($charger->company_id === $company->id, 404);
        $site = VendorSite::findOrFail($charger->site_id);
        abort_unless($site->company_id === $company->id && $site->station_id, 409, 'Link an owned catalog station before mapping inventory.');
        $station = Station::findOrFail($site->station_id);
        abort_unless($company->owner_id === $station->vendor_id && $station->country === $company->country && $site->country === $station->country && $station->source === 'simulator' && $charger->source === 'simulator', 409, 'Only owned linked simulator inventory is enabled.');

        return $station;
    }

    public function options(Station $station, ConnectorTopology $topology)
    {
        return ConnectorMapping::where('station_id', $station->id)->orderBy('id')->paginate(25)->through(fn ($map) => $topology->publicOption($station, $map));
    }

    public function evses(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $this->scope($r, $company, $charger);

        return Evse::where('charger_id', $charger->id)->orderBy('number')->paginate(25);
    }

    public function sources(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $station = $this->scope($r, $company, $charger);

        $query = DB::table('csms_bindings')->where('station_id', $station->id)->where(fn ($q) => $q->whereNull('vendor_charger_id')->orWhere('vendor_charger_id', $charger->id));
        if ($profile = AdmissionProfile::where('charger_id', $charger->id)->first()) {
            $query->where('identifier', $profile->identity)->where('protocol', $profile->protocol);
        }

        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('charger_admission_profiles as p')->whereColumn('p.identity', 'csms_bindings.identifier')->where('p.charger_id', '!=', $charger->id))->orderBy('id')->paginate(25, ['id', 'protocol']);
    }

    public function createEvse(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $station = $this->scope($r, $company, $charger, true);
        $v = $r->validate(['number' => 'required|integer|between:1,1000', 'mode' => 'required|in:application,csms', 'binding_id' => 'nullable|integer|min:1', 'source_evse_number' => 'required|integer|between:0,1000']);

        return DB::transaction(function () use ($r, $company, $charger, $station, $v) {
            Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->scope($r, $company, $charger, true);
            app(VendorAccess::class)->operational($company);
            abort_if(Evse::where('charger_id', $charger->id)->where('number', $v['number'])->exists(), 409, 'EVSE number already mapped.');
            if ($v['mode'] === 'csms') {
                $binding = DB::table('csms_bindings')->where('id', $v['binding_id'] ?? 0)->lockForUpdate()->first();
                abort_unless($binding && $binding->station_id === $station->id && ($binding->vendor_charger_id === null || $binding->vendor_charger_id === $charger->id) && $binding->protocol === $charger->ocpp_protocol && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $binding->identifier), 409, 'Source binding is incompatible or belongs to another charger.');
                $profile = AdmissionProfile::where('charger_id', $charger->id)->first();
                abort_if(($profile && ($profile->identity !== $binding->identifier || $profile->protocol !== $binding->protocol)) || AdmissionProfile::where('identity', $binding->identifier)->where('charger_id', '!=', $charger->id)->exists(), 409, 'Charger identity requires canonical ownership review.');
                abort_unless($binding->protocol === '1.6' ? (int) $v['source_evse_number'] === 0 : (int) $v['source_evse_number'] >= 1, 422, 'Use source EVSE 0 for OCPP 1.6 or a positive OCPP 2.0.1 EVSE.');
                abort_if(SessionLink::where('binding_id', $binding->id)->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->exists(), 409, 'Resolve existing source sessions before extending topology.');
                abort_if($v['source_evse_number'] > 0 && Evse::where('binding_id', $binding->id)->where('source_evse_number', $v['source_evse_number'])->exists(), 409, 'Source EVSE is already mapped.');
                DB::table('csms_bindings')->where('id', $binding->id)->update(['vendor_charger_id' => $charger->id]);
            } else {
                abort_unless(empty($v['binding_id']) && (int) $v['source_evse_number'] === 0, 422, 'Application simulators have no CSMS source.');
            }
            $evse = Evse::create($v + ['charger_id' => $charger->id]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'charging.evse_mapped', 'subject' => (string) $evse->id, 'created_at' => now()]);

            return response()->json($evse, 201);
        }, 3);
    }

    public function map(Request $r, VendorCompany $company, VendorCharger $charger, Evse $evse, VendorConnector $connector)
    {
        $station = $this->scope($r, $company, $charger, true);
        abort_unless($evse->charger_id === $charger->id && $connector->charger_id === $charger->id, 404);
        $v = $r->validate(['source_connector_number' => 'nullable|integer|between:1,100']);

        return DB::transaction(function () use ($r, $company, $charger, $station, $evse, $connector, $v) {
            Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->scope($r, $company, $charger, true);
            app(VendorAccess::class)->operational($company);
            abort_if(ConnectorMapping::where('connector_id', $connector->id)->exists(), 409, 'Connector identity is immutable once mapped.');
            abort_if(Reservation::where('station_id', $station->id)->whereNull('connector_mapping_id')->where('status', 'confirmed')->where('starts_at', '>', now()->subMinutes(30))->exists(), 409, 'Existing legacy bookings must finish or be cancelled before activation.');
            abort_if(SessionLink::whereNull('connector_mapping_id')->whereIn('binding_id', DB::table('csms_bindings')->where('station_id', $station->id)->select('id'))->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->exists(), 409, 'Reconcile unresolved legacy sessions before activation.');
            abort_if(DB::table('charging_sessions as s')->join('reservations as r', 'r.id', '=', 's.reservation_id')->where('r.station_id', $station->id)->whereNull('r.connector_mapping_id')->whereIn('s.status', ['pending_start', 'charging', 'stopping'])->exists(), 409, 'Finish legacy simulator sessions before activation.');
            abort_if(DB::table('charger_commands')->where('station_id', $station->id)->whereNull('payload->session_id')->where('status', '!=', 'cancelled')->exists(), 409, 'Uncorrelated legacy commands require reconciliation before activation.');
            $key = null;
            if ($evse->mode === 'csms') {
                abort_unless(isset($v['source_connector_number']), 422, 'An explicit source connector is required.');
                $key = hash('sha256', $evse->binding_id.':'.$evse->source_evse_number.':'.$v['source_connector_number']);
                abort_if(ConnectorMapping::where('source_target_key', $key)->exists(), 409, 'Source connector is already mapped.');
            } else {
                abort_unless(empty($v['source_connector_number']), 422, 'Application simulators have no source connector.');
            }
            $map = ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id, 'source_connector_number' => $v['source_connector_number'] ?? null, 'source_target_key' => $key]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'charging.connector_mapped', 'subject' => (string) $map->id, 'created_at' => now()]);

            return response()->json($map, 201);
        }, 3);
    }
}
