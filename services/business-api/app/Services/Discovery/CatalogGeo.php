<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;

/** Great-circle discovery distance, never driving distance or availability. */
final class CatalogGeo
{
    public const EARTH_KM = 6371.0088;

    public function within(Builder $query, float $latitude, float $longitude, float $radius): void
    {
        $angle = $radius / self::EARTH_KM;
        $delta = rad2deg($angle);
        $query->whereBetween('latitude', [max(-90, $latitude - $delta), min(90, $latitude + $delta)]);
        // A polar cap spans every longitude. Else use the spherical bound,
        // including wrapped intervals across the international date line.
        if (abs($latitude) + $delta < 90) {
            $width = rad2deg(asin(min(1, sin($angle) / cos(deg2rad($latitude)))));
            $low = $longitude - $width;
            $high = $longitude + $width;
            if ($low < -180) {
                $query->where(fn ($q) => $q->where('longitude', '>=', $low + 360)->orWhere('longitude', '<=', $high));
            } elseif ($high > 180) {
                $query->where(fn ($q) => $q->where('longitude', '>=', $low)->orWhere('longitude', '<=', $high - 360));
            } else {
                $query->whereBetween('longitude', [$low, $high]);
            }
        }

        // Haversine remains precise for coincident / very nearby points.
        // Clamp rounding before sqrt/asin; all input values stay bound.
        $radians = '0.017453292519943295';
        $a = "(sin((latitude - ?) * $radians / 2) * sin((latitude - ?) * $radians / 2) + cos(latitude * $radians) * cos(? * $radians) * sin((longitude - ?) * $radians / 2) * sin((longitude - ?) * $radians / 2))";
        $distance = '(2 * '.self::EARTH_KM." * asin(sqrt(CASE WHEN $a > 1 THEN 1 WHEN $a < 0 THEN 0 ELSE $a END)))";
        $one = [$latitude, $latitude, $latitude, $longitude, $longitude];
        $bindings = [...$one, ...$one, ...$one];
        $query->select('stations.*')->selectRaw("$distance as distance_km", $bindings)
            ->whereRaw("$distance <= ?", [...$bindings, $radius])
            ->orderBy('distance_km');
    }
}
