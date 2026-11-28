<?php

namespace App\Http\Controllers;

use App\Models\Charging\ConnectorMapping;
use App\Models\Station;
use App\Services\Charging\ChargingActorConsent;
use App\Services\Charging\ConnectorFreshness;
use App\Services\Charging\ConnectorTopology;
use App\Services\Charging\MaintenanceActor;
use App\Services\Charging\MaintenancePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Stored, mapped simulator reports. Never a physical availability or control grant. */
final class ConnectorFleetController extends Controller
{
    public function __invoke(Request $request)
    {
        $filters = $request->validate(['page' => 'sometimes|integer|min:1|max:1000000', 'station_id' => 'sometimes|integer|min:1']);

        return DB::transaction(function () use ($request, $filters) {
            $actor = app(ChargingActorConsent::class)->capture($request->user());
            abort_unless(in_array($actor->role, ['vendor', 'admin'], true), 403);
            app(MaintenanceActor::class)->checkBearer($request->user());
            $stations = Station::query()->when($actor->role !== 'admin', fn ($q) => $q->where('vendor_id', $actor->id))
                ->when(isset($filters['station_id']), fn ($q) => $q->whereKey($filters['station_id']));
            if (isset($filters['station_id'])) {
                abort_unless((clone $stations)->exists(), 404);
            }
            $page = ConnectorMapping::whereIn('station_id', (clone $stations)->select('id'))->orderBy('id')->paginate(25);
            // A reassignment cannot carry this page's station data into the new owner scope.
            $locked = (clone $stations)->whereIn('id', $page->pluck('station_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $page->through(function ($map) use ($locked) {
                $station = $locked->get($map->station_id);
                abort_unless($station, 409);
                $map = ConnectorMapping::findOrFail($map->id);
                abort_unless($map->station_id === $station->id, 409);
                // Navigation is independent of availability: blocked inventory still
                // needs diagnostics. Recheck canonical ownership without granting control.
                $navigation = DB::table('charging_evses as e')->join('vendor_chargers as ch', 'ch.id', '=', 'e.charger_id')
                    ->join('vendor_sites as site', 'site.id', '=', 'ch.site_id')->join('vendor_companies as co', 'co.id', '=', 'ch.company_id')
                    ->join('vendor_connectors as connector', 'connector.charger_id', '=', 'ch.id')
                    ->where('connector.id', $map->connector_id)
                    ->where('e.id', $map->evse_id)->whereColumn('site.company_id', 'co.id')->where('site.station_id', $station->id)
                    ->where('co.owner_id', $station->vendor_id)->where('co.country', $station->country)->where('site.country', $station->country)
                    ->where('ch.source', 'simulator')->where('ch.source', $station->source)->first(['co.id as company_id', 'ch.id as charger_id']);
                $freshness = app(ConnectorFreshness::class)->present(null, null, null);
                $protocol = null;
                $ready = false;
                try {
                    $target = app(ConnectorTopology::class)->target($station, $map->id);
                    abort_unless($target['map']->evse_id === $map->evse_id
                        && $target['map']->connector_id === $map->connector_id
                        && $target['map']->source_connector_number === $map->source_connector_number
                        && $target['map']->source_target_key === $map->source_target_key, 409,
                        'Fleet connector mapping changed; refresh before inspection.');
                    app(MaintenancePolicy::class)->requireAvailable($target['connector']->id);
                    $protocol = $target['binding']->protocol ?? null;
                    $ready = true;
                    $freshness = app(ConnectorFreshness::class)->present($target['binding'], $target['evse']->source_evse_number, $target['map']->source_connector_number);
                } catch (HttpException) {
                    // Missing/changed/blocked inventory is unknown, never a zero-fault success.
                }

                return ['mapping_id' => $map->id, 'station_id' => $station->id, 'station_name' => $station->name,
                    'operations_target' => $navigation ? ['company_id' => (int) $navigation->company_id, 'charger_id' => (int) $navigation->charger_id] : null,
                    'protocol' => $protocol, 'inventory_eligible_at_read' => $ready, 'status_freshness' => $freshness];
            });

            app(MaintenanceActor::class)->checkBearer($request->user());

            return response()->json(['scope' => 'recorded_connector_fleet', 'as_of' => now()->toISOString(), 'connectors' => $page,
                'evidence' => 'SIMULATED_SOURCE', 'physical_availability_verified' => false, 'remote_control' => false, 'billing_authority' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
