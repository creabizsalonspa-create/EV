<?php

namespace App\Services\Charging;

use App\Models\Station;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Operator policy only: never a live availability or physical control claim. */
final class StationAvailabilityBlocks
{
    public function publicForStations(Collection $ids): Collection
    {
        return DB::table('station_availability_blocks')->whereIn('station_id', $ids)->whereNull('cancelled_at')
            ->where('ends_at', '>', now())->orderBy('starts_at')->orderBy('id')->get()
            ->groupBy('station_id')->map(fn ($rows) => $rows->map(fn ($row) => [
                'id' => $row->id, 'station_id' => (int) $row->station_id,
                'starts_at' => CarbonImmutable::parse($row->starts_at, 'UTC')->toIso8601String(),
                'ends_at' => CarbonImmutable::parse($row->ends_at, 'UTC')->toIso8601String(),
                'reason_en' => $row->reason_en, 'reason_ar' => $row->reason_ar,
                'revision' => (int) $row->revision, 'cancelled' => false,
                'scope' => 'STATION_WIDE_OPERATOR_POLICY', 'physical_availability_verified' => false,
            ])->values()->all());
    }

    /** Station lock serializes publication with booking/start/dispatch gates. */
    public function requireWindow(Station $station, CarbonInterface $start, CarbonInterface $end): void
    {
        abort_if(DB::table('station_availability_blocks')->where('station_id', $station->id)
            ->whereNull('cancelled_at')->where('starts_at', '<', CarbonImmutable::instance($end)->utc())
            ->where('ends_at', '>', CarbonImmutable::instance($start)->utc())->exists(), 409,
            'The selected time overlaps an operator availability block.');
    }
}
