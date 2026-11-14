<?php

namespace App\Services\Charging;

/** Interval averages from accepted simulator meters, never instantaneous/grid demand. */
class ObservedSiteDemand
{
    public function project(array $spans): array
    {
        $events = [];
        foreach ($spans as $id => [$start, $end, $evse, $watts]) {
            $events[$start][] = [$evse, $id, $watts];
            $events[$end][] = [$evse, $id, null];
        }
        ksort($events, SORT_NUMERIC);
        $times = array_keys($events);
        $active = [];
        $maximum = null;
        $observed = 0;
        $ambiguous = 0;
        foreach ($times as $index => $time) {
            foreach ($events[$time] as [$evse, $id, $watts]) {
                if ($watts === null) {
                    unset($active[$evse][$id]);
                    if (! $active[$evse]) {
                        unset($active[$evse]);
                    }
                } else {
                    $active[$evse][$id] = $watts;
                }
            }
            $seconds = ($times[$index + 1] ?? $time) - $time;
            if ($seconds <= 0) {
                continue;
            }
            $sum = 0;
            $known = false;
            foreach ($active as $readings) {
                if (count($readings) !== 1) {
                    $ambiguous += $seconds;

                    continue;
                }
                $watts = reset($readings);
                abort_unless($sum <= OperationalAnalytics::MAX - $watts, 409, 'Report exceeds exact numeric bounds.');
                $sum += $watts;
                $known = true;
            }
            if ($known) {
                $observed += $seconds;
                $maximum = $maximum === null ? $sum : max($maximum, $sum);
            }
        }

        return ['maximum_observed_site_interval_average_w' => $maximum, 'demand_observed_seconds' => $observed, 'demand_ambiguous_evse_seconds' => $ambiguous];
    }
}
