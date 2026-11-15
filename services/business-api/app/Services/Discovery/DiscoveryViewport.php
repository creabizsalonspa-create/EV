<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Exact rectangular viewport selection for already-published local and OCPI data.
 *
 * No network requests, GPS collection, live-availability claims or charging
 * permissions. The antimeridian is expressed by west > east. All user inputs
 * are query bindings, never part of SQL expressions.
 */
final class DiscoveryViewport
{
    /** @param array{south: numeric-string|float, north: numeric-string|float, west: numeric-string|float, east: numeric-string|float} $bounds */
    public function apply(Builder $catalog, Builder $partner, array $bounds): void
    {
        $south = (float) $bounds['south'];
        $north = (float) $bounds['north'];
        $west = (float) $bounds['west'];
        $east = (float) $bounds['east'];

        $catalog->whereBetween('stations.latitude', [$south, $north]);
        if ($west < $east) {
            $catalog->whereBetween('stations.longitude', [$west, $east]);
        } else {
            $catalog->where(fn (Builder $query) => $query->where('stations.longitude', '>=', $west)
                ->orWhere('stations.longitude', '<=', $east));
        }

        // Normalized OCPI coordinates are numeric JSON. Missing, null, textual
        // and malformed coordinates must not turn into a false (0, 0) map pin.
        // Keep SQL expressions fixed for both supported test/production drivers.
        $driver = DB::connection()->getDriverName();
        [$latitude, $longitude, $validCoordinates] = match ($driver) {
            'pgsql' => [
                "(ocpi_records.normalized->>'latitude')::double precision",
                "(ocpi_records.normalized->>'longitude')::double precision",
                "jsonb_typeof(ocpi_records.normalized::jsonb->'latitude') = 'number' AND jsonb_typeof(ocpi_records.normalized::jsonb->'longitude') = 'number'",
            ],
            'sqlite' => [
                "CAST(json_extract(ocpi_records.normalized, '$.latitude') AS REAL)",
                "CAST(json_extract(ocpi_records.normalized, '$.longitude') AS REAL)",
                "json_type(ocpi_records.normalized, '$.latitude') IN ('integer', 'real') AND json_type(ocpi_records.normalized, '$.longitude') IN ('integer', 'real')",
            ],
            default => throw new \LogicException('Discovery viewport requires PostgreSQL or SQLite.'),
        };
        $partner->whereRaw($validCoordinates)
            ->whereRaw("$latitude BETWEEN ? AND ?", [$south, $north])
            ->whereRaw("$latitude BETWEEN -90 AND 90")
            ->whereRaw("$longitude BETWEEN -180 AND 180");
        if ($west < $east) {
            $partner->whereRaw("$longitude BETWEEN ? AND ?", [$west, $east]);
        } else {
            $partner->where(fn (Builder $query) => $query->whereRaw("$longitude >= ?", [$west])
                ->orWhereRaw("$longitude <= ?", [$east]));
        }
    }
}
