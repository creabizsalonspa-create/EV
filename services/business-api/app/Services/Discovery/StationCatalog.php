<?php

namespace App\Services\Discovery;

use App\Services\Charging\StationGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StationCatalog
{
    /** Public capabilities always come from the existing canonical station gate. */
    public function present(Collection $stations, ?int $customerId = null): Collection
    {
        $policies = app(StationGate::class)->policies($stations);
        $saved = $customerId === null ? collect() : DB::table('station_favorites')->where('user_id', $customerId)->whereIn('station_id', $stations->pluck('id'))->pluck('station_id')->flip();

        return $stations->map(fn ($s) => array_merge($s->toArray(), ['availability' => 'unknown', 'remote_control' => false, 'reservable' => $s->source === 'simulator' && ! in_array(($policies[$s->id] ?? 'legacy'), ['managed_blocked', 'canonical'], true), 'reservation_policy' => $policies[$s->id] ?? 'legacy', 'evidence' => $s->source === 'simulator' ? 'SIMULATED' : 'DISCOVERY_ONLY', ...($customerId === null ? [] : ['is_favorite' => $saved->has($s->id)])]));
    }
}
