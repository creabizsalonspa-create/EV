<?php

namespace App\Services\Discovery;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Optional, coarse, cache-bounded observations of actual licensed source fetches. */
final class LicensedSourceQuality
{
    private const FIELDS = ['successful_fetches', 'busy_responses', 'unavailable_responses', 'invalid_responses',
        'returned_record_observations', 'unknown_time_observations', 'older_than_24h_observations', 'unknown_operating_observations'];

    public function observe(Closure $fetch): array
    {
        try {
            $source = $fetch();
        } catch (LookupFailure $error) {
            $field = match ($error->reason) {
                'provider_busy' => 'busy_responses',
                'provider_unavailable' => 'unavailable_responses',
                'invalid_response' => 'invalid_responses',
                default => null,
            };
            if ($field !== null) {
                $this->record([$field => 1]);
            }
            throw $error;
        }
        // Only the newly normalized, unfiltered upstream sample is observed.
        // Cache hits, local filter results and request coordinates are excluded.
        $quality = $source['quality_sample'] ?? [];
        if (($quality['evidence'] ?? null) === 'FILTERED_LICENSED_CROWD_SAMPLE_ONLY') {
            $counts = ['successful_fetches' => 1,
                'returned_record_observations' => $quality['returned_count'],
                'unknown_time_observations' => $quality['source_time_unknown_or_future_count'],
                'older_than_24h_observations' => $quality['source_older_than_24h_count'],
                'unknown_operating_observations' => $quality['operational_report_unknown_count']];
            $this->record($counts);
        }

        return $source;
    }

    private function key(string $hour): string
    {
        return 'ocm-quality:v1:'.$hour;
    }

    private function valid(mixed $row): bool
    {
        if (! is_array($row) || array_keys($row) !== self::FIELDS) {
            return false;
        }
        foreach ($row as $count) {
            if (! is_int($count) || $count < 0 || $count > 25000000) {
                return false;
            }
        }

        return $row['returned_record_observations'] <= 25 * $row['successful_fetches']
            && $row['returned_record_observations'] >= $row['unknown_time_observations'] + $row['older_than_24h_observations']
            && $row['unknown_operating_observations'] <= $row['returned_record_observations'];
    }

    private function record(array $counts): void
    {
        if (config('discovery.ocm_quality_enabled') !== true) {
            return;
        }
        try {
            $hour = now()->utc()->startOfHour();
            $key = $this->key($hour->format('YmdH'));
            $lock = Cache::lock($key.':lock', 5);
            if (! $lock->get()) {
                return;
            }
            try {
                $row = Cache::get($key, array_fill_keys(self::FIELDS, 0));
                if (! $this->valid($row)) {
                    return;
                }
                foreach ($counts as $field => $count) {
                    if (! is_int($count) || $count < 0 || $count > 25) {
                        return;
                    }
                    $row[$field] += $count;
                }
                if ($this->valid($row)) {
                    Cache::put($key, $row, $hour->copy()->addHours(24));
                }
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            // Optional monitoring cannot fail a licensed discovery request.
            // No provider payload, credential or location is logged.
        }
    }

    public function report(): array
    {
        $enabled = config('discovery.ocm_quality_enabled') === true;
        $hour = now()->utc()->startOfHour();
        $samples = [];
        if ($enabled) {
            for ($i = 23; $i >= 0; $i--) {
                $at = $hour->copy()->subHours($i);
                try {
                    $row = Cache::get($this->key($at->format('YmdH')));
                    if ($this->valid($row)) {
                        $samples[] = ['hour_utc' => $at->toISOString()] + $row;
                    }
                } catch (Throwable) {
                    // Missing and unavailable cache windows are not zero observations.
                }
            }
        }

        return ['enabled' => $enabled, 'provider' => 'open_charge_map',
            'evidence' => 'BEST_EFFORT_COLD_FETCH_AGGREGATES_ONLY',
            'window_hours' => 24, 'observed_hours' => count($samples),
            'complete_geographic_coverage' => false, 'live_status_verified' => false,
            'samples' => $samples];
    }
}
