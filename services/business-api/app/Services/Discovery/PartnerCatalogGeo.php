<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Great-circle proximity over normalized, already-validated OCPI coordinates.
 * This is NOT road distance, charger occupancy, or an authorization source.
 */
final class PartnerCatalogGeo
{
    public const EARTH_KM = 6371.0088;

    /** @return array{latitude_min: float, latitude_max: float, longitude_ranges: array<int, array{float, float}>} */
    public static function bounds(float $latitude, float $longitude, float $radiusKm): array
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || ! is_finite($radiusKm) || abs($latitude) > 90 || abs($longitude) > 180 || $radiusKm < 1 || $radiusKm > 200) {
            throw new \InvalidArgumentException('Invalid bounded discovery coordinates.');
        }

        $angle = $radiusKm / self::EARTH_KM;
        $delta = rad2deg($angle);
        $ranges = [[-180.0, 180.0]];
        if (abs($latitude) + $delta < 90) {
            $width = rad2deg(asin(min(1, sin($angle) / cos(deg2rad($latitude)))));
            $low = $longitude - $width;
            $high = $longitude + $width;
            $ranges = $low < -180
                ? [[$low + 360, 180.0], [-180.0, $high]]
                : ($high > 180
                    ? [[$low, 180.0], [-180.0, $high - 360]]
                    : [[$low, $high]]);
        }

        return [
            'latitude_min' => max(-90, $latitude - $delta),
            'latitude_max' => min(90, $latitude + $delta),
            'longitude_ranges' => $ranges,
        ];
    }

    public function within(Builder $query, float $latitude, float $longitude, float $radiusKm): void
    {
        $bounds = self::bounds($latitude, $longitude, $radiusKm);
        // OCPI normalization restricts these JSON values to finite lat/lon.
        // The expressions below are static and never include user-provided SQL.
        $driver = DB::connection()->getDriverName();
        [$lat, $lng] = match ($driver) {
            'sqlite' => [
                "CAST(json_extract(ocpi_records.normalized, '$.latitude') AS REAL)",
                "CAST(json_extract(ocpi_records.normalized, '$.longitude') AS REAL)",
            ],
            'pgsql' => [
                "(ocpi_records.normalized->>'latitude')::double precision",
                "(ocpi_records.normalized->>'longitude')::double precision",
            ],
            default => throw new \LogicException('Partner catalog proximity is supported only on SQLite and PostgreSQL.'),
        };

        $query->whereRaw("$lat BETWEEN ? AND ?", [$bounds['latitude_min'], $bounds['latitude_max']]);
        $query->where(function ($q) use ($lng, $bounds) {
            foreach ($bounds['longitude_ranges'] as [$minimum, $maximum]) {
                $q->orWhereRaw("$lng BETWEEN ? AND ?", [$minimum, $maximum]);
            }
        });

        $radians = '0.017453292519943295';
        $a = "(sin(($lat - ?) * $radians / 2) * sin(($lat - ?) * $radians / 2) + cos($lat * $radians) * cos(? * $radians) * sin(($lng - ?) * $radians / 2) * sin(($lng - ?) * $radians / 2))";
        $distance = '(2 * '.self::EARTH_KM." * asin(sqrt(CASE WHEN $a > 1 THEN 1 WHEN $a < 0 THEN 0 ELSE $a END)))";
        $one = [$latitude, $latitude, $latitude, $longitude, $longitude];
        $bindings = [...$one, ...$one, ...$one];

        $query->select('ocpi_records.*')
            ->selectRaw("$distance AS distance_km", $bindings)
            ->whereRaw("$distance <= ?", [...$bindings, $radiusKm])
            ->orderBy('distance_km');
    }
}
