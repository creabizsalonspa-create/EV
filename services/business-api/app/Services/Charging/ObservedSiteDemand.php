<?php

namespace App\Services\Charging;

/** Interval averages from accepted simulator meters, never instantaneous/grid demand. */
class ObservedSiteDemand
{
    public function project(array $spans): array
    {
        $events = [];
        foreach ($spans as $id => [$start, $end, $evse, $watts]) {
            $events[$start][$evse][] = [$id, $watts];
            $events[$end][$evse][] = [$id, null];
        }
        ksort($events, SORT_NUMERIC);
        $times = array_keys($events);
        $active = [];
        $siteWatts = 0;
        $knownEvses = 0;
        $ambiguousEvses = 0;
        $maximum = null;
        $observed = 0;
        $ambiguous = 0;

        foreach ($times as $index => $time) {
            $seconds = ($times[$index + 1] ?? $time) - $time;
            if ($seconds <= 0) {
                break;
            }

            $newContributions = [];
            foreach ($events[$time] as $evse => $changes) {
                $previousCount = count($active[$evse] ?? []);
                if ($previousCount === 1) {
                    $siteWatts -= reset($active[$evse]);
                    $knownEvses--;
                }
                $ambiguousEvses -= (int) ($previousCount > 1);

                foreach ($changes as [$id, $watts]) {
                    if ($watts === null) {
                        unset($active[$evse][$id]);
                    } else {
                        $active[$evse][$id] = $watts;
                    }
                }

                $count = count($active[$evse] ?? []);
                if ($count === 0) {
                    unset($active[$evse]);
                } elseif ($count === 1) {
                    $newContributions[] = reset($active[$evse]);
                    $knownEvses++;
                }
                $ambiguousEvses += (int) ($count > 1);
            }

            // An EVSE ending at this instant is no longer active before
            // any new contribution is counted, regardless of source order.
            foreach ($newContributions as $watts) {
                abort_unless($siteWatts <= OperationalAnalytics::MAX - $watts, 409, 'Report exceeds exact numeric bounds.');
                $siteWatts += $watts;
            }

            $ambiguous += $ambiguousEvses * $seconds;
            if ($knownEvses > 0) {
                $observed += $seconds;
                $maximum = $maximum === null ? $siteWatts : max($maximum, $siteWatts);
            }
        }

        return ['maximum_observed_site_interval_average_w' => $maximum, 'demand_observed_seconds' => $observed, 'demand_ambiguous_evse_seconds' => $ambiguous];
    }
}
