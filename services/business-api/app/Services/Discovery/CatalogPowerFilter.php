<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;

/**
 * Query the canonical published station rating, not connector telemetry.
 *
 * Supplier-reported partner power is deliberately excluded by the caller:
 * OCPI catalog records are unverified and the shape is not a reliable
 * cross-provider current capacity or booking/power entitlement.
 */
final class CatalogPowerFilter
{
    /** @param array<string, mixed> $filters */
    public function apply(Builder $catalog, array $filters): void
    {
        if (isset($filters['min_power_kw'])) {
            $catalog->where('stations.power_kw', '>=', (int) $filters['min_power_kw']);
        }
        if (isset($filters['max_power_kw'])) {
            $catalog->where('stations.power_kw', '<=', (int) $filters['max_power_kw']);
        }
    }
}
