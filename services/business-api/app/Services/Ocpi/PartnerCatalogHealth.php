<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Discovery\PartnerCatalogPolicy;
use Carbon\CarbonImmutable;

/** Caller holds the scoped provider lock also used by canonical record ingress. */
final class PartnerCatalogHealth
{
    public function snapshot(OcpiProvider $provider): array
    {
        $checked = CarbonImmutable::now('UTC');
        $policy = PartnerCatalogPolicy::maxAge();
        $report = ['state' => 'unavailable', 'checked_at' => $checked->toISOString(),
            'max_age_seconds' => $policy, 'published_count' => null,
            'within_policy_count' => null, 'stale_count' => null, 'unknown_age_count' => null, 'future_clock_count' => null,
            'evidence' => 'PARTNER_CATALOG_TIMESTAMPS_ONLY', 'physical_availability_verified' => false,
            'remote_control' => false, 'billing_authority' => false];
        if (! $provider->authorized || ! User::whereKey($provider->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists()) {
            return $report;
        }
        if ($policy === null) {
            return array_replace($report, ['state' => 'policy_unavailable']);
        }
        $now = $checked->format('Y-m-d H:i:s.u');
        $cutoff = $checked->subSeconds($policy)->format('Y-m-d H:i:s.u');
        $records = OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', 'locations')->where('normalized->publish', true);
        $total = (clone $records)->count();
        $timestamped = (clone $records)->whereNotNull('source_updated_at')->whereNotNull('received_at');
        if ($records->getConnection()->getDriverName() === 'sqlite') {
            // PostgreSQL timestamp columns reject this malformed legacy storage.
            // Keep original text comparisons for strict microsecond boundaries.
            foreach (['source_updated_at', 'received_at'] as $column) {
                $timestamped->whereRaw("julianday($column) IS NOT NULL AND strftime('%Y-%m-%d', $column, '+0 days') = substr($column, 1, 10)");
            }
        }
        $valid = (clone $timestamped)->where('source_updated_at', '<=', $now)->where('received_at', '<=', $now);
        $recent = (clone $valid)->where('source_updated_at', '>', $cutoff)->where('received_at', '>', $cutoff)->count();
        $stale = (clone $valid)->where(fn ($q) => $q->where('source_updated_at', '<=', $cutoff)->orWhere('received_at', '<=', $cutoff))->count();
        $future = (clone $timestamped)->where(fn ($q) => $q->where('source_updated_at', '>', $now)->orWhere('received_at', '>', $now))->count();
        $unknown = $total - $recent - $stale;
        if ($unknown < 0 || $future > $unknown) {
            return $report;
        }

        return array_replace($report, ['state' => $total === 0 ? 'empty' : ($stale + $unknown > 0 ? 'attention' : 'within_policy'),
            'published_count' => $total, 'within_policy_count' => $recent, 'stale_count' => $stale,
            'unknown_age_count' => $unknown, 'future_clock_count' => $future]);
    }
}
