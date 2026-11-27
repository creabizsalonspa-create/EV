<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Bounded observation accounting. Neither heartbeats nor silence prove status. */
final class SourceStatusDurations
{
    public const WINDOW_SECONDS = 86400;

    public const MAX_HOLD_SECONDS = 300;

    public const MAX_EVENTS = 12000;

    public function report(object $binding): array
    {
        $asOf = CarbonImmutable::now('UTC');
        $end = $asOf->startOfSecond();
        $start = $end->subSeconds(self::WINDOW_SECONDS);
        $rows = DB::table('charging_status_observations')
            ->where('binding_id', $binding->id)
            ->where('source_generation', FaultMonitor::generation($binding))
            ->where('occurred_at', '>=', $start->subSeconds(self::MAX_HOLD_SECONDS))
            // Preserve subsecond read precision: a real observation in the
            // current second exists, although it contributes no whole elapsed
            // second yet. DateTime bindings otherwise truncate microseconds.
            ->where('occurred_at', '<=', $asOf->format('Y-m-d H:i:s.u'))
            ->orderBy('occurred_at')->orderBy('source_event_id')->limit(self::MAX_EVENTS + 1)->get();
        $base = ['window_started_at' => $start->toISOString(), 'window_ended_at' => $end->toISOString(),
            'window_seconds' => self::WINDOW_SECONDS, 'max_observation_hold_seconds' => self::MAX_HOLD_SECONDS,
            'basis' => 'BOUNDED_SIMULATED_STATUS_OBSERVATIONS', 'physical_uptime_verified' => false,
            'commands_sent' => false, 'components' => []];
        if ($rows->count() > self::MAX_EVENTS) {
            return $base + ['state' => 'requires_review', 'reason' => 'observation_budget_exceeded'];
        }
        // The source event ID is the canonical progression boundary. If the
        // stored observation timestamp order disagrees with source ID order,
        // historical imports or corruption may make older "Available" states
        // appear newer than a subsequent fault. Do not publish partial uptime
        // durations from such contradictory evidence.
        $lastSourceId = 0;
        foreach ($rows as $row) {
            if (! is_int($row->source_event_id) && ! ctype_digit((string) $row->source_event_id)) {
                return $base + ['state' => 'requires_review', 'reason' => 'source_event_order_conflict'];
            }
            if ((int) $row->source_event_id <= $lastSourceId) {
                return $base + ['state' => 'requires_review', 'reason' => 'source_event_order_conflict'];
            }
            $lastSourceId = (int) $row->source_event_id;
        }
        $components = [];
        $statuses = match ($binding->protocol) {
            '1.6' => ['Available', 'Preparing', 'Charging', 'SuspendedEVSE', 'SuspendedEV', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'],
            '2.0.1' => ['Available', 'Occupied', 'Reserved', 'Unavailable', 'Faulted'],
            default => [],
        };
        foreach ($rows as $row) {
            $key = $row->evse_number.':'.$row->connector_number;
            $instant = CarbonImmutable::parse($row->occurred_at, 'UTC');
            $time = $instant->getTimestamp();
            $micros = $time * 1000000 + (int) $instant->format('u');
            $status = in_array($row->status, $statuses, true) ? $row->status : 'Unknown';
            if ($status !== 'Unknown' && ! in_array($row->error_code, [null, 'NoError'], true)) {
                $status = 'Faulted';
            } elseif ($binding->protocol === '1.6' && $status === 'Available' && $row->error_code !== 'NoError') {
                // Old or imported status rows must not turn missing OCPP 1.6
                // fault clearance into observed healthy time.
                $status = 'Unknown';
            }
            // Conflicting observations at the same instant have no defensible
            // winner. Preserve the ambiguity rather than choosing by record ID.
            $old = $components[$key][$time] ?? null;
            $components[$key][$time] = [
                'status' => $old === null || $old['status'] === $status ? $status : 'Unknown',
                'first' => $old === null ? $micros : min($old['first'], $micros),
                'last' => $old === null ? $micros : max($old['last'], $micros),
            ];
        }
        if (count($components) > 64) {
            return $base + ['state' => 'requires_review', 'reason' => 'component_budget_exceeded'];
        }
        foreach ($components as $key => $timeline) {
            ksort($timeline, SORT_NUMERIC);
            $times = array_keys($timeline);
            $durationMicros = [];
            $startMicros = $start->getTimestamp() * 1000000;
            $endMicros = $end->getTimestamp() * 1000000;
            foreach ($times as $index => $time) {
                $sample = $timeline[$time];
                $next = isset($times[$index + 1]) ? $timeline[$times[$index + 1]]['first'] : $endMicros;
                $until = min($next, $sample['last'] + self::MAX_HOLD_SECONDS * 1000000, $endMicros);
                $elapsed = max(0, $until - max($sample['first'], $startMicros));
                if ($sample['status'] !== 'Unknown' && $elapsed > 0) {
                    $durationMicros[$sample['status']] = ($durationMicros[$sample['status']] ?? 0) + $elapsed;
                }
            }
            // Round down only after summing disjoint precise intervals for each
            // status. Any fractional remainder stays in unknown time.
            $durations = array_filter(array_map(fn ($micros) => intdiv($micros, 1000000), $durationMicros));
            [$evse, $connector] = array_map('intval', explode(':', $key));
            $base['components'][] = ['evse_number' => $evse, 'connector_number' => $connector,
                'observed_status_seconds' => (object) $durations, 'unknown_seconds' => self::WINDOW_SECONDS - array_sum($durations)];
        }

        return $base + ['state' => $rows->isEmpty() ? 'not_observed' : 'observed'];
    }
}
