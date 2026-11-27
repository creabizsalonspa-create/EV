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

        // OCPI normalization stores validated numeric coordinates as JSON
        // strings. SQLite CAST turns invalid text into zero, while PostgreSQL
        // CAST can throw; both drivers must reject malformed source metadata.
        $driver = DB::connection()->getDriverName();
        // The finite decimal/scientific envelope is deliberately bounded:
        // a 40-digit mantissa with an exponent <= 99 cannot overflow a
        // double. Raw OCPI coordinates were validated at normalization.
        $safeNumber = "'^[+-]?([0-9]{1,40}([.][0-9]{0,40})?|[.][0-9]{1,40})([eE][+-]?[0-9]{1,2})?$'";
        $latText = "btrim(ocpi_records.normalized->>'latitude')";
        $lngText = "btrim(ocpi_records.normalized->>'longitude')";
        [$latitude, $longitude] = match ($driver) {
            'pgsql' => [
                "CASE WHEN $latText ~ $safeNumber THEN $latText::double precision ELSE NULL END",
                "CASE WHEN $lngText ~ $safeNumber THEN $lngText::double precision ELSE NULL END",
            ],
            'sqlite' => [
                "CAST(discovery_coordinate_number(json_extract(ocpi_records.normalized, '$.latitude')) AS REAL)",
                "CAST(discovery_coordinate_number(json_extract(ocpi_records.normalized, '$.longitude')) AS REAL)",
            ],
            default => throw new \LogicException('Discovery viewport requires PostgreSQL or SQLite.'),
        };
        if ($driver === 'sqlite') {
            SqliteGeoMath::register(DB::connection()->getPdo());
            // json_extract converts JSON booleans to integers before PHP
            // sees them. Preserve the original JSON scalar type so a
            // corrupt true/false coordinate can never become a 1/0 pin.
            $partner->whereRaw("json_type(ocpi_records.normalized, '$.latitude') IN ('text', 'integer', 'real')")
                ->whereRaw("json_type(ocpi_records.normalized, '$.longitude') IN ('text', 'integer', 'real')");
        }
        $partner->whereRaw("$latitude BETWEEN ? AND ?", [$south, $north])
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
