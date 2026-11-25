<?php

namespace App\Services\Discovery;

use App\Models\OcpiRecord;
use Carbon\CarbonInterface;

final class PartnerCatalogPolicy
{
    public static function maxAge(): ?int
    {
        $raw = config('partner_catalog.max_age_seconds');
        if (! is_int($raw) && (! is_string($raw) || ! preg_match('/^[1-9][0-9]{1,5}$/D', $raw))) {
            return null;
        }
        $seconds = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 60, 'max_range' => 604800]]);

        return $seconds === false ? null : $seconds;
    }

    public static function present(OcpiRecord $record, CarbonInterface $checked): array
    {
        $limit = self::maxAge();
        $source = PartnerCatalogAge::storedInstant($record, 'source_updated_at');
        $receipt = PartnerCatalogAge::storedInstant($record, 'received_at');
        $state = 'unknown';
        if ($limit === null) {
            $state = 'policy_unavailable';
        } elseif ($source !== null && $receipt !== null && ! $source->greaterThan($checked) && ! $receipt->greaterThan($checked)) {
            $cutoff = $checked->copy()->subSeconds($limit);
            $state = $source->lessThanOrEqualTo($cutoff) || $receipt->lessThanOrEqualTo($cutoff) ? 'stale' : 'within_policy';
        }

        return ['state' => $state, 'max_age_seconds' => $limit, 'checked_at' => $checked->toISOString(),
            'evidence' => 'PARTNER_CATALOG_TIMESTAMPS_ONLY', 'physical_availability_verified' => false,
            'remote_control' => false, 'billing_authority' => false];
    }
}
