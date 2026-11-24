<?php

namespace App\Services\Discovery;

use Throwable;

/** Offline, explicit public benchmark coverage sampling; never starts provider I/O. */
final class LicensedCoverageAudit
{
    public function __construct(private LicensedSourceCache $sources, private OpenChargeMapAdapter $adapter) {}

    public function report(): array
    {
        $report = ['enabled' => false, 'provider' => 'open_charge_map',
            'evidence' => 'APPROVED_PUBLIC_BENCHMARK_CACHE_SAMPLE_ONLY',
            'complete_geographic_coverage' => false, 'live_status_verified' => false,
            'configured_areas' => 0, 'observed_areas' => 0, 'areas_with_reports' => 0, 'areas' => []];
        $key = config('discovery.ocm_api_key');
        if (config('discovery.ocm_coverage_enabled') !== true
            || config('discovery.ocm_enabled') !== true || config('discovery.ocm_terms_accepted') !== true
            || ! is_string($key) || strlen(trim($key)) < 12 || strlen($key) > 512) {
            return $report + ['reason' => 'not_configured'];
        }
        $areas = config('discovery.ocm_coverage_areas');
        if (! is_array($areas) || ! array_is_list($areas) || count($areas) < 1 || count($areas) > 25) {
            return $report + ['reason' => 'invalid_benchmarks'];
        }
        $identities = [];
        $names = [];
        // Validate every configured benchmark before inspecting any cached data.
        foreach ($areas as $area) {
            if (! is_array($area) || array_diff(array_keys($area), ['label', 'latitude', 'longitude', 'radius_km', 'country']) !== []
                || ! is_string($area['label'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $area['label'])
                || ! $this->coordinate($area['latitude'] ?? null, -90, 90)
                || ! $this->coordinate($area['longitude'] ?? null, -180, 180)
                || ! $this->coordinate($area['radius_km'] ?? null, 1, 200)
                || ! is_string($area['country'] ?? null) || ! preg_match('/^[A-Z]{2}$/D', $area['country'])
                || isset($names[$area['label']])) {
                return $report + ['reason' => 'invalid_benchmarks'];
            }
            $identity = LicensedSourceCache::identity((float) $area['latitude'], (float) $area['longitude'],
                (float) $area['radius_km'], $area['country'], $key);
            if (isset($identities[$identity])) {
                return $report + ['reason' => 'invalid_benchmarks'];
            }
            $names[$area['label']] = true;
            $identities[$identity] = $area;
        }
        $report['enabled'] = true;
        $report['configured_areas'] = count($identities);
        foreach ($identities as $identity => $area) {
            $entry = ['label' => $area['label'], 'country' => $area['country'], 'state' => 'unobserved', 'quality_sample' => null];
            try {
                $cached = $this->sources->peek($identity);
                if ($cached !== null) {
                    $source = $this->adapter->filterReported($cached);
                    $this->adapter->assertGeography($source, (float) $area['latitude'], (float) $area['longitude'],
                        (float) $area['radius_km'], $area['country']);
                    $entry['quality_sample'] = $source['quality_sample'];
                    $entry['state'] = $source['count'] === 0 ? 'observed_empty' : 'observed_reports';
                    $report['observed_areas']++;
                    if ($source['count'] > 0) {
                        $report['areas_with_reports']++;
                    }
                }
            } catch (Throwable) {
                $entry['state'] = 'unverified_cache';
            }
            $report['areas'][] = $entry;
        }

        return $report + ['reason' => null];
    }

    private function coordinate(mixed $value, float $min, float $max): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $min && $value <= $max;
    }
}
