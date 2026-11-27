<?php

namespace App\Services\Discovery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Bounded, operator-only history of validated public benchmark cache samples. */
final class LicensedCoverageHistory
{
    public const RETENTION_HOURS = 168;

    private const FIELDS = [
        'configured_areas', 'observed_areas', 'areas_with_reports', 'areas_empty',
        'unobserved_areas', 'unverified_areas', 'returned_record_observations',
        'unknown_time_observations', 'older_than_24h_observations', 'unknown_operating_observations',
    ];

    public function __construct(private LicensedCoverageAudit $audit) {}

    public function capture(): array
    {
        $current = $this->audit->report();
        if (! $current['enabled']) {
            return ['state' => 'disabled', 'reason' => $current['reason']];
        }

        $row = array_fill_keys(self::FIELDS, 0);
        $row['configured_areas'] = $current['configured_areas'];
        foreach ($current['areas'] as $area) {
            if ($area['state'] === 'unobserved') {
                $row['unobserved_areas']++;

                continue;
            }
            if ($area['state'] === 'unverified_cache') {
                $row['unverified_areas']++;

                continue;
            }
            $row['observed_areas']++;
            $row[$area['state'] === 'observed_reports' ? 'areas_with_reports' : 'areas_empty']++;
            $sample = $area['quality_sample'];
            $row['returned_record_observations'] += $sample['returned_count'];
            $row['unknown_time_observations'] += $sample['source_time_unknown_or_future_count'];
            $row['older_than_24h_observations'] += $sample['source_older_than_24h_count'];
            $row['unknown_operating_observations'] += $sample['operational_report_unknown_count'];
        }

        if (! $this->valid($row)) {
            return ['state' => 'unverified_sample'];
        }
        if ($row['observed_areas'] === 0) {
            // Do not consume an hour's slot when no validated source exists.
            return ['state' => 'no_observations'];
        }

        try {
            $hour = CarbonImmutable::now('UTC')->startOfHour();
            $key = $this->key($this->scope(), $hour);
            if (Cache::add($key, $row, $hour->addHours(self::RETENTION_HOURS))) {
                return ['state' => 'captured', 'hour_utc' => $hour->toISOString()];
            }

            return ['state' => $this->valid(Cache::get($key)) ? 'already_captured' : 'storage_unavailable',
                'hour_utc' => $hour->toISOString()];
        } catch (Throwable) {
            return ['state' => 'storage_unavailable'];
        }
    }

    public function report(): array
    {
        $current = $this->audit->report();
        $report = [
            'enabled' => $current['enabled'],
            'reason' => $current['reason'],
            'provider' => 'open_charge_map',
            'evidence' => 'APPROVED_PUBLIC_BENCHMARK_HOURLY_CACHE_SAMPLES_ONLY',
            'window_hours' => self::RETENTION_HOURS,
            'configured_areas' => $current['configured_areas'],
            'observed_hours' => 0,
            'unverified_hours' => 0,
            'complete_geographic_coverage' => false,
            'live_status_verified' => false,
            'samples' => [],
        ];
        if (! $current['enabled']) {
            return $report;
        }

        $scope = $this->scope();
        $now = CarbonImmutable::now('UTC')->startOfHour();
        for ($i = self::RETENTION_HOURS - 1; $i >= 0; $i--) {
            $hour = $now->subHours($i);
            try {
                $row = Cache::get($this->key($scope, $hour));
                if ($row === null) {
                    continue;
                }
                if (! $this->valid($row) || $row['configured_areas'] !== $current['configured_areas']
                    || $row['observed_areas'] === 0) {
                    $report['unverified_hours']++;

                    continue;
                }
                $report['samples'][] = ['hour_utc' => $hour->toISOString()] + $row;
            } catch (Throwable) {
                $report['unverified_hours']++;
            }
        }
        $report['observed_hours'] = count($report['samples']);

        return $report;
    }

    private function scope(): string
    {
        // Never mix samples across changes in approved benchmarks or credential.
        // Neither raw coordinates nor the credential are included in cache keys.
        return hash('sha256', json_encode([
            config('discovery.ocm_coverage_areas'),
            hash('sha256', (string) config('discovery.ocm_api_key')),
        ], JSON_THROW_ON_ERROR));
    }

    private function key(string $scope, CarbonImmutable $hour): string
    {
        return 'ocm-coverage-history:v1:'.$scope.':'.$hour->format('YmdH');
    }

    private function valid(mixed $row): bool
    {
        if (!  is_array($row) || array_keys($row) !== self::FIELDS) {
            return false;
        }
        foreach ($row as $count) {
            if (!  is_int($count) || $count < 0 || $count > 625) {
                return false;
            }
        }

        return $row['configured_areas'] >= 1 && $row['configured_areas'] <= 25
            && $row['observed_areas'] + $row['unobserved_areas'] + $row['unverified_areas'] === $row['configured_areas']
            && $row['areas_with_reports'] + $row['areas_empty'] === $row['observed_areas']
            && $row['returned_record_observations'] >= $row['areas_with_reports']
            && $row['returned_record_observations'] <= 25 * $row['areas_with_reports']
            && $row['unknown_time_observations'] + $row['older_than_24h_observations'] <= $row['returned_record_observations']
            && $row['unknown_operating_observations'] <= $row['returned_record_observations'];
    }
}
