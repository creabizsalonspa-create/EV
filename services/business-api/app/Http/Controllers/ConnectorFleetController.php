<?php

namespace App\Http\Controllers;

use App\Models\Charging\ConnectorMapping;
use App\Models\Station;
use App\Services\Charging\ChargingActorConsent;
use App\Services\Charging\ConnectorFreshness;
use App\Services\Charging\ConnectorTopology;
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
                $freshness = app(ConnectorFreshness::class)->present(null, null, null);
                $protocol = null;
                $ready = false;
                try {
                    $target = app(ConnectorTopology::class)->target($station, $map->id);
                    app(MaintenancePolicy::class)->requireAvailable($target['connector']->id);
                    $protocol = $target['binding']->protocol ?? null;
                    $ready = true;
                    $freshness = app(ConnectorFreshness::class)->present($target['binding'], $target['evse']->source_evse_number, $map->source_connector_number);
                } catch (HttpException) {
                    // Missing/changed/blocked inventory is unknown, never a zero-fault success.
                }

                return ['mapping_id' => $map->id, 'station_id' => $station->id, 'station_name' => $station->name,
                    'protocol' => $protocol, 'inventory_eligible_at_read' => $ready, 'status_freshness' => $freshness];
            });

            return response()->json(['scope' => 'recorded_connector_fleet', 'as_of' => now()->toISOString(), 'connectors' => $page,
                'evidence' => 'SIMULATED_SOURCE', 'physical_availability_verified' => false, 'remote_control' => false, 'billing_authority' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
