<?php

namespace App\Services\Discovery;

use App\Services\Charging\ConnectorFreshness;
use App\Services\Charging\FaultMonitor;
use App\Services\Charging\SourceTargetIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** A recent accepted simulator observation is evidence, never occupancy authority. */
final class SourceReportFilter
{
    public function apply(Builder $stations, array $filters): void
    {
        if (empty($filters['recent_source_status'])) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $pdo->sqliteCreateFunction('ev_binding_generation', fn ($id, $station, $charger, $identifier, $protocol) => FaultMonitor::generation((object) ['id' => (int) $id, 'station_id' => (int) $station, 'vendor_charger_id' => $charger === null ? null : (int) $charger, 'identifier' => $identifier, 'protocol' => $protocol]), 5);
            $pdo->sqliteCreateFunction('ev_source_target_matches', static fn ($protocol, $binding, $evse, $connector, $digest) =>
                is_string($protocol) && SourceTargetIdentity::matches($protocol, $binding, $evse, $connector, $digest) ? 1 : 0, 5);
            $pdo->sqliteCreateFunction('ev_source_identifier', fn ($value) => is_string($value) && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $value) === 1 ? 1 : 0, 1);
            $pdo->sqliteCreateFunction('ev_license_valid', static function ($expiry, $timezone, $at) {
                try {
                    return CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $expiry, 0, 10), $timezone)->endOfDay()->gt(CarbonImmutable::parse($at, 'UTC')) ? 1 : 0;
                } catch (\Throwable) {
                    return 0;
                }
            }, 3);
        }
        $generation = match ($driver) {
            'sqlite' => 'ev_binding_generation(b.id, b.station_id, b.vendor_charger_id, b.identifier, b.protocol)',
            // The admission identifier grammar excludes JSON metacharacters.
            // These typed, server-owned fields reproduce FaultMonitor's compact JSON.
            'pgsql' => "encode(sha256(convert_to('[' || b.id::text || ',' || b.station_id::text || ',' || COALESCE(b.vendor_charger_id::text, 'null') || ',\"' || b.identifier || '\",\"' || b.protocol || '\"]', 'UTF8')), 'hex')",
            default => throw new \LogicException('Unsupported source-report search database.'),
        };
        // The SQL predicate must run before pagination, and use the exact
        // digest contract already enforced by ConnectorTopology::target.
        $target = match ($driver) {
            'sqlite' => 'ev_source_target_matches(b.protocol, b.id, e.source_evse_number, m.source_connector_number, m.source_target_key) = 1',
            'pgsql' => "m.source_target_key = encode(sha256(convert_to(b.id::text || ':' || e.source_evse_number::text || ':' || m.source_connector_number::text, 'UTF8')), 'hex')",
        };
        $stations->where('stations.source', 'simulator')->whereExists(function ($q) use ($filters, $now, $driver, $generation, $target) {
            $q->selectRaw('1')->from('charging_connector_mappings as m')
                ->join('charging_evses as e', 'e.id', '=', 'm.evse_id')
                ->join('vendor_connectors as c', 'c.id', '=', 'm.connector_id')
                ->join('vendor_chargers as ch', 'ch.id', '=', 'e.charger_id')
                ->join('vendor_sites as site', 'site.id', '=', 'ch.site_id')
                ->join('vendor_companies as co', 'co.id', '=', 'ch.company_id')
                ->join('regions as region', 'region.country', '=', 'co.country')
                ->join('csms_bindings as b', 'b.id', '=', 'e.binding_id')
                ->join('charging_status_observations as o', function ($join) use ($generation) {
                    $join->on('o.binding_id', '=', 'b.id')->on('o.evse_number', '=', 'e.source_evse_number')->on('o.connector_number', '=', 'm.source_connector_number')->whereRaw('o.source_generation = '.$generation);
                })
                ->whereColumn('m.station_id', 'stations.id')->whereColumn('site.station_id', 'stations.id')
                ->whereColumn('site.company_id', 'co.id')->whereColumn('co.owner_id', 'stations.vendor_id')
                ->whereColumn('co.country', 'stations.country')->whereColumn('site.country', 'stations.country')
                ->whereColumn('c.charger_id', 'ch.id')->whereColumn('b.station_id', 'stations.id')
                ->whereColumn('b.vendor_charger_id', 'ch.id')->whereColumn('b.protocol', 'ch.ocpp_protocol')
                ->where('co.status', 'approved')->where('ch.source', 'simulator')->where('e.mode', 'csms')
                ->where('c.maintenance_blocked', false)->where('b.id', '>', 0)
                ->whereBetween('m.source_connector_number', [1, 100])
                ->where(fn ($protocol) => $protocol->where(fn ($v) => $v->where('b.protocol', '1.6')->where('e.source_evse_number', 0))->orWhere(fn ($v) => $v->where('b.protocol', '2.0.1')->whereBetween('e.source_evse_number', [1, 1000])))
                ->whereRaw($target)
                ->whereRaw($driver === 'sqlite' ? 'ev_source_identifier(b.identifier) = 1' : "b.identifier ~ '^ev-test-[a-zA-Z0-9-]{1,80}$'")
                ->whereRaw($driver === 'sqlite' ? 'ev_license_valid(co.license_expires_on, region.timezone, ?) = 1' : "((co.license_expires_on + 1)::timestamp - interval '1 microsecond') > timezone(region.timezone, ?::timestamptz)", [$now->format('Y-m-d H:i:s.uP')])
                ->where('o.occurred_at', '>', $now->subSeconds(300)->format('Y-m-d H:i:s.u'))->where('o.occurred_at', '<=', $now->format('Y-m-d H:i:s.u'))
                ->where('o.created_at', '<=', $now->format('Y-m-d H:i:s.u'))->whereIn('o.status', ConnectorFreshness::STATUSES);
            if (isset($filters['connector_type'])) {
                $q->where('c.connector_type', $filters['connector_type']);
            }
            if (isset($filters['min_power_kw'])) {
                $q->where('c.max_power_kw', '>=', $filters['min_power_kw']);
            }
            if ($filters['recent_source_status'] === 'Faulted') {
                $q->where(fn ($match) => $match->where('o.status', 'Faulted')->orWhereRaw('COALESCE(o.error_code, ?) <> ?', ['NoError', 'NoError']));
            } else {
                $q->where('o.status', $filters['recent_source_status'])->whereRaw('COALESCE(o.error_code, ?) = ?', ['NoError', 'NoError']);
                if ($filters['recent_source_status'] === 'Available') {
                    // Match ConnectorFreshness: absent 1.6 errorCode cannot
                    // establish availability; 2.0.1 has no mandatory code.
                    $q->where(fn ($code) => $code->where('b.protocol', '2.0.1')->orWhere('o.error_code', 'NoError'));
                }
            }
            // A future/newer observation suppresses old claims; ID orders identical instants.
            $q->whereNotExists(function ($later) {
                $this->component($later);
                $later->where(fn ($newer) => $newer->whereColumn('later.occurred_at', '>', 'o.occurred_at')->orWhere(fn ($tie) => $tie->whereColumn('later.occurred_at', 'o.occurred_at')->whereColumn('later.id', '>', 'o.id')));
            })->whereNotExists(function ($bySource) {
                // A newer source ID with an older clock is a contradiction,
                // not permission to reuse the old latest-by-time receipt.
                $this->component($bySource);
                $bySource->whereColumn('later.source_event_id', '>', 'o.source_event_id');
            })->whereNotExists(function ($conflict) {
                $this->component($conflict);
                $conflict->whereColumn('later.occurred_at', 'o.occurred_at')->where(fn ($different) => $different->whereColumn('later.status', '!=', 'o.status')->orWhereRaw('COALESCE(later.error_code, ?) <> COALESCE(o.error_code, ?)', ['NoError', 'NoError']));
            });
        });
    }

    private function component($query): void
    {
        $query->selectRaw('1')->from('charging_status_observations as later')
            ->whereColumn('later.binding_id', 'o.binding_id')->whereColumn('later.source_generation', 'o.source_generation')
            ->whereColumn('later.evse_number', 'o.evse_number')->whereColumn('later.connector_number', 'o.connector_number');
    }
}
