<?php

namespace App\Services\Charging;

use App\Models\VendorOps\VendorCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only canonical simulator facts. Missing telemetry is unknown, never uptime. */
class OperationalAnalytics
{
    public const MAX = 9007199254740991;

    private function add(int $a, int $b): int
    {
        abort_unless($b >= 0 && $a <= self::MAX - $b, 409, 'Report exceeds exact numeric bounds.');

        return $a + $b;
    }

    private function seconds(mixed $value): int
    {
        return CarbonImmutable::parse($value)->getTimestamp();
    }

    public function report(VendorCompany $company, array $filters, bool $financial = false): array
    {
        $asOf = CarbonImmutable::now('UTC');
        $from = CarbonImmutable::parse($filters['from'] ?? $asOf->subDays(6)->toDateString(), 'UTC')->startOfDay();
        $until = CarbonImmutable::parse($filters['to'] ?? $asOf->toDateString(), 'UTC')->startOfDay()->addDay();
        abort_unless($from->lt($until) && $until->lte($asOf->addDay()->startOfDay()) && $from->diffInDays($until) <= 31, 422, 'Select one to31UTC days ending today or earlier.');
        $until = $until->min($asOf);
        $a = $from->getTimestamp();
        $z = $until->getTimestamp();
        abort_unless($a < $z, 422);
        $sites = DB::table('vendor_sites as v')->join('stations as s', 's.id', '=', 'v.station_id')->where('v.company_id', $company->id)->where('s.vendor_id', $company->owner_id)
            ->when(isset($filters['site_id']), fn ($q) => $q->where('v.id', $filters['site_id']))->select('v.id', 'v.name', 'v.station_id', 's.source as station_source')->limit(501)->get();
        abort_if($sites->count() > 500, 409, 'Narrow the site filter.');
        if (isset($filters['site_id'])) {
            abort_unless($sites->count() === 1, 404);
        }
        $bindings = DB::table('csms_bindings as b')->join('vendor_chargers as c', 'c.id', '=', 'b.vendor_charger_id')->where('c.company_id', $company->id)->whereIn('c.site_id', $sites->pluck('id'))->where('c.source', 'simulator')->whereColumn('c.ocpp_protocol', 'b.protocol')->select('b.*', 'c.site_id')->limit(501)->get();
        abort_if($bindings->count() > 500, 409, 'Narrow the site filter.');
        $siteByStation = $sites->keyBy('station_id');
        $bindings = $bindings->filter(fn ($b) => isset($siteByStation[$b->station_id]) && $siteByStation[$b->station_id]->station_source === 'simulator' && $siteByStation[$b->station_id]->id === $b->site_id && in_array($b->protocol, ['1.6', '2.0.1'], true));
        $evses = DB::table('charging_evses')->whereIn('binding_id', $bindings->pluck('id'))->whereIn('charger_id', $bindings->pluck('vendor_charger_id'))->limit(501)->get();
        abort_if($evses->count() > 500, 409, 'Narrow the site filter.');
        $bindingOwners = $bindings->keyBy('id');
        $evses = $evses->filter(fn ($e) => $e->charger_id === $bindingOwners[$e->binding_id]->vendor_charger_id);
        $maps = DB::table('charging_connector_mappings')->whereIn('evse_id', $evses->pluck('id'))->limit(1001)->get();
        abort_if($maps->count() > 1000, 409, 'Narrow the site filter.');
        $byBinding = $bindings->keyBy('id');
        $byEvse = $evses->keyBy('id');
        $byMap = $maps->keyBy('id');
        $rows = [];
        foreach ($sites as $s) {
            $rows[$s->id] = ['site_id' => $s->id, 'name' => $s->name, 'mapped_evses' => 0, 'mapped_connectors' => 0, 'source_started' => 0, 'source_completed' => 0, 'pending_source' => 0, 'excluded_sessions' => 0, 'completed_energy_wh' => 0, 'observed_busy_seconds' => 0, 'possible_evse_seconds' => 0, 'status_known_seconds' => 0, 'status_unknown_seconds' => 0, 'reported_fault_seconds' => 0, 'possible_connector_seconds' => 0, 'maximum_session_interval_average_w' => 0];
        }
        foreach ($evses as $evse) {
            $site = $byBinding[$evse->binding_id]->site_id;
            $rows[$site]['mapped_evses']++;
            $rows[$site]['possible_evse_seconds'] += $z - $a;
        }
        $links = DB::table('csms_session_links as l')->join('charging_sessions as s', 's.id', '=', 'l.charging_session_id')->join('reservations as r', 'r.id', '=', 's.reservation_id')->whereIn('l.binding_id', $bindings->pluck('id'))->where('l.created_at', '<=', $asOf)->whereNull('l.closed_without_dispatch_at')
            ->where(fn ($q) => $q->whereNull('l.ended_at')->orWhere('l.ended_at', '>=', $from))->select('l.*', 's.energy_wh', 's.status as session_status', 'r.station_id as reservation_station')->limit(10001)->get();
        abort_if($links->count() > 10000, 409, 'Narrow the report period/site.');
        $events = DB::table('csms_inbox_events')->whereIn('link_id', $links->pluck('id'))->where('outcome', 'applied')->where('created_at', '<=', $asOf)->where('occurred_at', '<=', $asOf)->where(fn ($q) => $q->whereIn('type', ['started', 'ended'])->orWhereBetween('occurred_at', [$from, $until]))->orderBy('sequence')->limit(20001)->get();
        abort_if($events->count() > 20000, 409, 'Narrow the report period/site.');
        $byLink = $events->groupBy('link_id');
        $intervals = [];
        $validCompleted = [];
        foreach ($links as $l) {
            $b = $byBinding[$l->binding_id];
            $site = $b->site_id;
            $map = $l->connector_mapping_id ? ($byMap[$l->connector_mapping_id] ?? null) : null;
            $evse = $map ? ($byEvse[$map->evse_id] ?? null) : null;
            if (! $map || ! $evse || $evse->binding_id !== $b->id || $map->station_id !== $b->station_id || $l->reservation_station !== $b->station_id || $l->identifier !== $b->identifier || $l->protocol !== $b->protocol) {
                $rows[$site]['excluded_sessions']++;

                continue;
            }
            $source = $byLink[$l->id] ?? collect();
            $start = $source->first(fn ($e) => $e->type === 'started' && $e->sequence === 0);
            if (! $start || ! $l->started_at || ! $l->transaction_id || $this->seconds($start->occurred_at) !== $this->seconds($l->started_at) || $start->meter_wh !== $l->meter_start_wh) {
                $rows[$site]['pending_source']++;

                continue;
            }
            $startAt = $this->seconds($l->started_at);
            if ($startAt >= $z) {
                continue;
            }
            if ($startAt >= $a) {
                $rows[$site]['source_started']++;
            }
            $end = $source->first(fn ($e) => $e->type === 'ended' && $e->sequence === $l->last_sequence);
            $completed = $l->ended_at && $end && $this->seconds($end->occurred_at) === $this->seconds($l->ended_at) && $l->session_status === 'stopped' && $end->meter_wh === $l->last_meter_wh && $l->last_meter_wh >= $l->meter_start_wh && $l->energy_wh === $l->last_meter_wh - $l->meter_start_wh;
            if ($l->ended_at && ! $completed) {
                $rows[$site]['excluded_sessions']++;

                continue;
            }
            $last = $source->last();
            $endAt = $completed ? $this->seconds($l->ended_at) : min($last ? $this->seconds($last->occurred_at) : $startAt, $startAt + 86400 * 31);
            if ($endAt > $startAt) {
                $intervals[$site][$evse->id][] = [max($a, $startAt), min($z, $endAt)];
            }
            if ($completed && $endAt >= $a && $endAt < $z) {
                $rows[$site]['source_completed']++;
                $rows[$site]['completed_energy_wh'] = $this->add($rows[$site]['completed_energy_wh'], (int) $l->energy_wh);
                $validCompleted[] = $l->charging_session_id;
            }
            $previous = null;
            foreach ($source as $e) {
                $time = $this->seconds($e->occurred_at);
                if ($previous && $previous[0] >= $a && $time <= $z && $time > $previous[0] && $e->meter_wh >= $previous[1]) {
                    $watts = intdiv(((int) $e->meter_wh - $previous[1]) * 3600, $time - $previous[0]);
                    $rows[$site]['maximum_session_interval_average_w'] = max($rows[$site]['maximum_session_interval_average_w'], $watts);
                }
                $previous = [$time, (int) $e->meter_wh];
            }
        }
        foreach ($intervals as $site => $groups) {
            foreach ($groups as $spans) {
                usort($spans, fn ($x, $y) => $x[0] <=> $y[0]);
                $end = 0;
                foreach ($spans as [$s,$e]) {
                    if ($e > max($s, $end)) {
                        $rows[$site]['observed_busy_seconds'] += $e - max($s, $end);
                    } $end = max($end, $e);
                }
            }
        }
        $observations = DB::table('charging_status_observations')->whereIn('binding_id', $bindings->pluck('id'))->where('occurred_at', '>=', $from->subSeconds(300))->where('occurred_at', '<=', $until)->where('created_at', '<=', $asOf)->orderBy('occurred_at')->orderBy('source_event_id')->limit(20001)->get();
        abort_if($observations->count() > 20000, 409, 'Narrow the report period/site.');
        $components = [];
        foreach ($maps as $m) {
            $evse = $byEvse[$m->evse_id];
            $b = $byBinding[$evse->binding_id];
            if ($m->source_connector_number === null || $m->station_id !== $b->station_id) {
                continue;
            }
            $key = $b->id.':'.$evse->source_evse_number.':'.$m->source_connector_number;
            abort_if(isset($components[$key]), 409, 'Ambiguous source connector mapping.');
            $components[$key] = ['site' => $b->site_id, 'generation' => FaultMonitor::generation($b), 'events' => []];
            $rows[$b->site_id]['mapped_connectors']++;
            $rows[$b->site_id]['possible_connector_seconds'] += $z - $a;
        }
        foreach ($observations as $o) {
            $key = $o->binding_id.':'.$o->evse_number.':'.$o->connector_number;
            if (isset($components[$key]) && hash_equals($components[$key]['generation'], $o->source_generation)) {
                $components[$key]['events'][] = $o;
            }
        }
        foreach ($components as $component) {
            $timeline = [];
            foreach ($component['events'] as $e) {
                $t = $this->seconds($e->occurred_at);
                $state = $e->status.'|'.($e->error_code ?? '');
                if (isset($timeline[$t]) && $timeline[$t]['identity'] !== $state) {
                    $timeline[$t]['ambiguous'] = true;
                } else {
                    $timeline[$t] ??= ['identity' => $state, 'ambiguous' => false, 'fault' => $e->status === 'Faulted' || ! in_array($e->error_code, [null, 'NoError'], true), 'status' => $e->status];
                }
            }
            $times = array_keys($timeline);
            $known = 0;
            $fault = 0;
            foreach ($times as $i => $t) {
                $e = $timeline[$t];
                $seconds = max(0, min($z, $t + 300, $times[$i + 1] ?? $z) - max($a, $t));
                if ($e['ambiguous'] || ! in_array($e['status'], ['Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'], true)) {
                    continue;
                } $known += $seconds;
                if ($e['fault']) {
                    $fault += $seconds;
                }
            }
            $site = $component['site'];
            $rows[$site]['status_known_seconds'] += $known;
            $rows[$site]['reported_fault_seconds'] += $fault;
        }
        foreach ($rows as &$row) {
            $row['status_unknown_seconds'] = $row['possible_connector_seconds'] - $row['status_known_seconds'];
        }unset($row);
        // Canonical invoice facts stay separate by currency, precision and provenance.
        $money = [];
        foreach (($financial ? DB::table('finance_invoices')->whereIn('charging_session_id', $validCompleted)->where('vendor_id', $company->owner_id)->where('created_at', '<=', $asOf)->get(['currency', 'minor_units', 'evidence', 'total_minor', 'vendor_minor']) : collect()) as $i) {
            $key = $i->currency.':'.$i->minor_units.':'.$i->evidence;
            $money[$key] ??= ['currency' => $i->currency, 'minor_units' => $i->minor_units, 'evidence' => $i->evidence, 'invoices' => 0, 'invoiced_minor' => 0, 'vendor_allocated_minor' => 0];
            $money[$key]['invoices']++;
            $money[$key]['invoiced_minor'] = $this->add($money[$key]['invoiced_minor'], (int) $i->total_minor);
            $money[$key]['vendor_allocated_minor'] = $this->add($money[$key]['vendor_allocated_minor'], (int) $i->vendor_minor);
        }

        return ['company_id' => $company->id, 'from' => $from->toDateString(), 'to' => CarbonImmutable::parse($filters['to'] ?? $asOf->toDateString(), 'UTC')->toDateString(), 'as_of' => $asOf->toISOString(), 'until' => $until->toISOString(), 'window_seconds' => $z - $a, 'status_freshness_seconds' => 300, 'sites' => array_values($rows), 'invoice_facts' => array_values($money), 'evidence' => 'SIMULATED_SOURCE', 'physical_uptime_verified' => false, 'billing_authority' => false];
    }

    public function csv(array $report): string
    {
        $f = fopen('php://temp', 'w+');
        $safe = fn ($v) => is_string($v) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $v) ? "'".$v : $v;
        fputcsv($f, ['SIMULATED_SOURCE', 'from_utc', $report['from'], 'to_utc', $report['to'], 'as_of', $report['as_of']], ',', '"', '');
        if ($report['sites']) {
            fputcsv($f, array_keys($report['sites'][0]), ',', '"', '');
            foreach ($report['sites'] as $row) {
                fputcsv($f, array_map($safe, array_values($row)), ',', '"', '');
            }
        }
        fputcsv($f, ['INVOICES_NOT_CASH_OR_SETTLEMENT'], ',', '"', '');
        if ($report['invoice_facts']) {
            fputcsv($f, array_keys($report['invoice_facts'][0]), ',', '"', '');
            foreach ($report['invoice_facts'] as $row) {
                fputcsv($f, array_map($safe, array_values($row)), ',', '"', '');
            }
        }
        rewind($f);
        $text = stream_get_contents($f);
        fclose($f);

        return $text;
    }
}
