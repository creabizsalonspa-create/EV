<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Bounded stored catalog observations. No upstream lookup or charging authority. */
final class CatalogObservations
{
    public const LIMIT = 10000;

    private function bucket(?string $value, CarbonImmutable $at): string
    {
        if ($value === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):([0-5]\d):([0-5]\d)(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4])(?::?[0-5]\d)?)?$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23) {
            return 'unknown';
        }
        try {
            $time = CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            return 'unknown';
        }
        if ($time->gt($at)) {
            return 'clock_ahead';
        }
        foreach (['within_1_hour' => $at->subHour(), 'within_24_hours' => $at->subDay(), 'within_7_days' => $at->subDays(7)] as $label => $floor) {
            if ($time->gte($floor)) {
                return $label;
            }
        }

        return 'older_than_7_days';
    }

    public function read(User $snapshot, OcpiProvider $provider, string $module): array
    {
        abort_unless(in_array($module, ['all', 'locations', 'tariffs'], true), 422);

        return DB::transaction(function () use ($snapshot, $provider, $module) {
            $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->account_status === 'active' && in_array($actor->role, ['vendor', 'admin'], true), 403);
            abort_unless($actor->role === $snapshot->role && (int) $actor->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $actor->password, (string) $snapshot->password), 409, 'Account credentials changed. Sign in again.');
            $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin' || $current->owner_id === $actor->id, 404);
            $at = CarbonImmutable::now('UTC');
            $query = DB::table('ocpi_records')->where('ocpi_provider_id', $current->id)
                ->whereIn('module', $module === 'all' ? ['locations', 'tariffs'] : [$module])
                ->select('module', 'source_updated_at', 'received_at');
            if (DB::connection()->getDriverName() === 'pgsql') {
                foreach (['latitude', 'longitude'] as $key) {
                    $query->selectRaw("CASE WHEN json_typeof(normalized->'$key') IN ('string','number') AND length(normalized->>'$key') <= 128 THEN normalized->>'$key' ELSE NULL END AS $key");
                }
                $query->selectRaw("CASE WHEN json_typeof(normalized->'publish') = 'boolean' THEN CASE WHEN normalized->>'publish' = 'true' THEN 1 ELSE 0 END ELSE NULL END AS publish_value")
                    ->selectRaw("CASE WHEN json_typeof(normalized->'connector_standards') = 'array' THEN CASE WHEN json_array_length(normalized->'connector_standards') > 0 THEN 1 ELSE 0 END ELSE NULL END AS connector_list");
            } elseif (DB::connection()->getDriverName() === 'sqlite') {
                foreach (['latitude', 'longitude'] as $key) {
                    $query->selectRaw("CASE WHEN json_type(normalized, '$.$key') IN ('text','integer','real') AND length(CAST(json_extract(normalized, '$.$key') AS TEXT)) <= 128 THEN json_extract(normalized, '$.$key') ELSE NULL END AS $key");
                }
                $query->selectRaw("CASE json_type(normalized, '$.publish') WHEN 'true' THEN 1 WHEN 'false' THEN 0 ELSE NULL END AS publish_value")
                    ->selectRaw("CASE WHEN json_type(normalized, '$.connector_standards') = 'array' THEN CASE WHEN json_array_length(normalized, '$.connector_standards') > 0 THEN 1 ELSE 0 END ELSE NULL END AS connector_list");
            } else {
                abort(503, 'Catalog observation storage is unsupported.');
            }
            $query->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM ocpi_catalog_retirements r WHERE r.record_id = ocpi_records.id AND r.content_hash = ocpi_records.content_hash) THEN 1 ELSE 0 END AS retired');
            $rows = $query->orderBy('id')->limit(self::LIMIT + 1)->get();
            abort_if($rows->count() > self::LIMIT, 422, 'Catalog exceeds the bounded report size. Select one module; no partial counts are returned.');
            $buckets = array_fill_keys(['within_1_hour', 'within_24_hours', 'within_7_days', 'older_than_7_days', 'unknown', 'clock_ahead'], 0);
            $modules = [];
            foreach ($module === 'all' ? ['locations', 'tariffs'] : [$module] as $name) {
                $modules[$name] = ['total' => 0, 'reported_age' => $buckets, 'receipt_age' => $buckets];
                $modules[$name] += $name === 'locations'
                    ? array_fill_keys(['stored_publish_true', 'stored_publish_false', 'stored_publish_unknown', 'coordinates_usable', 'coordinates_unknown_or_invalid', 'connector_list_present', 'connector_list_empty', 'connector_list_unknown'], 0)
                    : ['current_version_retired' => 0];
            }
            foreach ($rows as $row) {
                $entry = &$modules[$row->module];
                $entry['total']++;
                $entry['reported_age'][$this->bucket($row->source_updated_at, $at)]++;
                $entry['receipt_age'][$this->bucket($row->received_at, $at)]++;
                if ($row->module === 'locations') {
                    $entry[$row->publish_value === null ? 'stored_publish_unknown' : ((int) $row->publish_value === 1 ? 'stored_publish_true' : 'stored_publish_false')]++;
                    $usable = is_numeric($row->latitude) && is_numeric($row->longitude) && is_finite((float) $row->latitude) && is_finite((float) $row->longitude)
                        && (float) $row->latitude >= -90 && (float) $row->latitude <= 90 && (float) $row->longitude >= -180 && (float) $row->longitude <= 180;
                    $entry[$usable ? 'coordinates_usable' : 'coordinates_unknown_or_invalid']++;
                    $entry[$row->connector_list === null ? 'connector_list_unknown' : ((int) $row->connector_list === 1 ? 'connector_list_present' : 'connector_list_empty')]++;
                } else {
                    $entry['current_version_retired'] += (int) $row->retired;
                }
                unset($entry);
            }

            return ['scope' => 'stored_partner_catalog_observations', 'as_of' => $at->toISOString(), 'module_filter' => $module, 'record_limit' => self::LIMIT,
                'provider' => ['id' => $current->id, 'name' => $current->name, 'authorized' => $current->authorized], 'modules' => $modules,
                'receipt_refreshes_source_age' => false, 'live_availability_verified' => false, 'physical_charging_verified' => false, 'remote_control' => false, 'billing_authority' => false];
        }, 3);
    }
}
