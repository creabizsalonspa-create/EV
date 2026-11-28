<?php

namespace App\Http\Controllers;

use App\Services\Charging\ChargingActorConsent;
use App\Services\Charging\MaintenanceActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Inventory targets for explicit source inspection, never a registration or health receipt. */
final class RegisteredChargerFleetController extends Controller
{
    public function __invoke(Request $request)
    {
        $filters = $request->validate(['page' => 'sometimes|integer|min:1|max:1000000', 'station_id' => 'sometimes|integer|min:1']);

        return DB::transaction(function () use ($request, $filters) {
            $actor = app(ChargingActorConsent::class)->capture($request->user());
            app(MaintenanceActor::class)->checkBearer($request->user());
            abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);
            $query = DB::table('vendor_chargers as charger')
                ->join('vendor_companies as company', 'company.id', '=', 'charger.company_id')
                ->join('vendor_sites as site', 'site.id', '=', 'charger.site_id')
                ->join('stations as station', 'station.id', '=', 'site.station_id')
                ->whereColumn('site.company_id', 'company.id')
                ->whereColumn('station.vendor_id', 'company.owner_id')
                ->whereColumn('station.country', 'company.country')
                ->whereColumn('site.country', 'company.country')
                ->where('station.source', 'direct_unverified')
                ->where('charger.source', 'direct_unverified')
                ->whereIn('charger.ocpp_protocol', ['1.6', '2.0.1'])
                ->when($actor->role !== 'admin', fn ($q) => $q->where('company.owner_id', $actor->id))
                ->when(isset($filters['station_id']), fn ($q) => $q->where('station.id', $filters['station_id']));
            if (isset($filters['station_id'])) {
                abort_unless(DB::table('stations')->where('id', $filters['station_id'])
                    ->when($actor->role !== 'admin', fn ($q) => $q->where('vendor_id', $actor->id))->exists(), 404);
            }
            $columns = ['charger.id as charger_id', 'company.id as company_id', 'station.id as station_id',
                'station.name as station_name', 'charger.asset_reference', 'charger.ocpp_protocol as protocol'];
            $page = (clone $query)->orderBy('charger.id')->paginate(25, $columns);
            // Re-read and lock the exact projected targets. Ownership changes
            // must not leak an old page to a former owner.
            $locked = (clone $query)->whereIn('charger.id', $page->pluck('charger_id'))
                ->orderBy('charger.id')->lockForUpdate()->get($columns);
            abort_unless($locked->all() == $page->items(), 409, 'Direct fleet inventory changed; refresh explicitly.');
            $page->through(fn ($row) => [
                'company_id' => (int) $row->company_id,
                'charger_id' => (int) $row->charger_id,
                'station_id' => (int) $row->station_id,
                'station_name' => $row->station_name,
                'asset_reference' => $row->asset_reference,
                'protocol' => $row->protocol,
                'state' => 'not_inspected',
            ]);
            app(MaintenanceActor::class)->checkBearer($request->user());

            return response()->json(['scope' => 'direct_charger_fleet', 'as_of' => now()->toISOString(),
                'chargers' => $page, 'evidence' => 'INVENTORY_ONLY', 'physical_availability_verified' => false,
                'remote_control_authorized' => false, 'billing_authority' => false])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
