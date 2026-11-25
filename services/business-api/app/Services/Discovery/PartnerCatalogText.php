<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Search only fields with a shape admitted by the public partner catalog. */
final class PartnerCatalogText
{
    public static function field(string $field): string
    {
        if (! in_array($field, ['name', 'city', 'address'], true)) {
            throw new \LogicException('Unsupported partner catalog text field.');
        }

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "CASE WHEN json_typeof(ocpi_records.normalized->'$field') = 'string' AND length(ocpi_records.normalized->>'$field') <= 255 THEN ocpi_records.normalized->>'$field' ELSE NULL END",
            'sqlite' => "CASE WHEN json_type(ocpi_records.normalized, '$.$field') = 'text' AND length(json_extract(ocpi_records.normalized, '$.$field')) <= 255 THEN json_extract(ocpi_records.normalized, '$.$field') ELSE NULL END",
            default => throw new \LogicException('Unsupported partner catalog search database.'),
        };
    }

    public static function standard(Builder $query, string $standard): void
    {
        // Do not allow a scalar or object to masquerade as a connector list.
        $shape = match (DB::connection()->getDriverName()) {
            'pgsql' => "json_typeof(ocpi_records.normalized->'connector_standards') = 'array'",
            'sqlite' => "json_type(ocpi_records.normalized, '$.connector_standards') = 'array'",
            default => throw new \LogicException('Unsupported partner catalog search database.'),
        };
        $query->whereRaw($shape)->whereJsonContains('normalized->connector_standards', $standard);
    }
}
