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
        $end = CarbonImmutable::now('UTC')->startOfSecond();
        $start = $end->subSeconds(self::WINDOW_SECONDS);
        $rows = DB::table('charging_status_observations')
            ->where('source_generation', FaultMonitor::generation($binding))
            ->where('occurred_at', '>=', $start->subSeconds(self::MAX_HOLD_SECONDS))
            ->where('occurred_at', '<=', $end)
            ->orderBy('occurred_at')->orderBy('id')->limit(self::MAX_EVENTS + 1)->get();
        $base = ['window_started_at' => $start->toISOString(), 'window_ended_at' => $end->toISOString(),
            'window_seconds' => self::WINDOW_SECONDS, 'max_observation_hold_seconds' => self::MAX_HOLD_SECONDS,
            'basis' => 'BOUNDED_SIMULATED_STATUS_OBSERVATIONS', 'physical_uptime_verified' => false,
            'commands_sent' => false, 'components' => []];
        if ($rows->count() > self::MAX_EVENTS) {
            return $base + ['state' => 'requires_review', 'reason' => 'observation_budget_exceeded'];
        }
        $components = [];
        foreach ($rows as $row) {
            $key = $row->evse_number.':'.$row->connector_number;
            $time = CarbonImmutable::parse($row->occurred_at, 'UTC')->getTimestamp();
            $status = in_array($row->status, ['Available', 'Preparing', 'Charging', 'SuspendedEVSE', 'SuspendedEV', 'Finishing', 'Reserved', 'Unavailable', 'Faulted', 'Occupied'], true)
                ? $row->status : 'Unknown';
            if (! in_array($row->error_code, [null, 'NoError'], true)) {
                $status = 'Faulted';
            }
            // Conflicting observations at the same instant have no defensible
            // winner. Preserve the ambiguity rather than choosing by record ID.
            $old = $components[$key][$time] ?? null;
            $components[$key][$time] = $old === null || $old === $status ? $status : 'Unknown';
        }
        if (count($components) > 64) {
            return $base + ['state' => 'requires_review', 'reason' => 'component_budget_exceeded'];
        }
        foreach ($components as $key => $timeline) {
            ksort($timeline, SORT_NUMERIC);
            $times = array_keys($timeline);
            $durations = [];
            foreach ($times as $index => $time) {
                $until = min($times[$index + 1] ?? $end->getTimestamp(), $time + self::MAX_HOLD_SECONDS, $end->getTimestamp());
                $seconds = max(0, $until - max($time, $start->getTimestamp()));
                if ($timeline[$time] !== 'Unknown' && $seconds > 0) {
                    $durations[$timeline[$time]] = ($durations[$timeline[$time]] ?? 0) + $seconds;
                }
            }
            [$evse, $connector] = array_map('intval', explode(':', $key));
            $base['components'][] = ['evse_number' => $evse, 'connector_number' => $connector,
                'observed_status_seconds' => (object) $durations, 'unknown_seconds' => self::WINDOW_SECONDS - array_sum($durations)];
        }

        return $base + ['state' => $rows->isEmpty() ? 'not_observed' : 'observed'];
    }
}
