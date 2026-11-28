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
            'pgsql' => "CASE WHEN json_typeof(ocpi_records.normalized->'$field') = 'string' AND length(ocpi_records.normalized->>'$field') BETWEEN 1 AND 255 AND (ocpi_records.normalized->>'$field') COLLATE \"C\" !~ ('[' || chr(1) || '-' || chr(31) || chr(127) || ']') THEN ocpi_records.normalized->>'$field' ELSE NULL END",
            'sqlite' => "CASE WHEN json_type(ocpi_records.normalized, '$.$field') = 'text' AND length(json_extract(ocpi_records.normalized, '$.$field')) BETWEEN 1 AND 255 AND instr(json_extract(ocpi_records.normalized, '$.$field'), char(0)) = 0 AND json_extract(ocpi_records.normalized, '$.$field') NOT GLOB ('*[' || char(1) || '-' || char(31) || char(127) || ']*') THEN json_extract(ocpi_records.normalized, '$.$field') ELSE NULL END",
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

    /** Only an EVSE/connector visible in the bounded public projection can
     * establish a reported-state filter match. This is not live availability. */
    public static function reportedState(Builder $query, string $state, ?string $standard): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $evse = "CASE WHEN evse.type = 'object' THEN evse.value ELSE '{}' END";
            $connector = "CASE WHEN connector.type = 'object' THEN connector.value ELSE '{}' END";
            $safe = static function (string $object, string $key): string {
                $value = "json_extract($object, '$.$key')";

                return "json_type($object, '$.$key') = 'text' AND length($value) BETWEEN 1 AND 64 AND instr($value, char(0)) = 0 AND $value NOT GLOB ('*[' || char(1) || '-' || char(31) || char(127) || ']*')";
            };
            $uid = $safe($evse, 'uid');
            $id = $safe($connector, 'id');
            $type = $safe($connector, 'standard');
            $sql = "EXISTS (SELECT 1 FROM json_each(CASE WHEN json_type(ocpi_records.normalized, '$.evses') = 'array' THEN json_extract(ocpi_records.normalized, '$.evses') ELSE '[]' END) evse WHERE evse.key < 100 AND $uid AND json_extract($evse, '$.reported_status') = ?";
            if ($standard !== null) {
                $sql .= " AND EXISTS (SELECT 1 FROM json_each(CASE WHEN json_type($evse, '$.connectors') = 'array' THEN json_extract($evse, '$.connectors') ELSE '[]' END) connector WHERE connector.key < 20 AND $id AND $type AND json_extract($connector, '$.standard') = ?)";
            }
        } elseif ($driver === 'pgsql') {
            $safe = static function (string $object, string $key): string {
                $value = "$object->>'$key'";

                return "jsonb_typeof($object->'$key') = 'string' AND length($value) BETWEEN 1 AND 64 AND ($value) COLLATE \"C\" !~ ('[' || chr(1) || '-' || chr(31) || chr(127) || ']')";
            };
            $uid = $safe('evse.value', 'uid');
            $id = $safe('connector.value', 'id');
            $type = $safe('connector.value', 'standard');
            $sql = "EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(ocpi_records.normalized::jsonb->'evses') = 'array' THEN ocpi_records.normalized::jsonb->'evses' ELSE '[]'::jsonb END) WITH ORDINALITY AS evse(value, ordinal) WHERE evse.ordinal <= 100 AND $uid AND evse.value->>'reported_status' = ?";
            if ($standard !== null) {
                $sql .= " AND EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(evse.value->'connectors') = 'array' THEN evse.value->'connectors' ELSE '[]'::jsonb END) WITH ORDINALITY AS connector(value, ordinal) WHERE connector.ordinal <= 20 AND $id AND $type AND connector.value->>'standard' = ?)";
            }
        } else {
            throw new \LogicException('Unsupported partner EVSE state search database.');
        }
        $query->whereRaw($sql.')', $standard === null ? [$state] : [$state, $standard]);
    }
}
