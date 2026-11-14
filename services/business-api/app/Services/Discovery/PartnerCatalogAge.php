<?php

namespace App\Services\Discovery;

use App\Models\OcpiRecord;
use Carbon\CarbonInterface;

/** Catalog timestamps describe reports, never physical connector availability. */
final class PartnerCatalogAge
{
    public static function present(OcpiRecord $record, CarbonInterface $checkedAt): array
    {
        $now = $checkedAt->getTimestamp();
        $source = $record->source_updated_at?->getTimestamp();
        $receipt = $record->received_at?->getTimestamp();
        $sourceAhead = $record->source_updated_at?->greaterThan($checkedAt) ?? false;
        $receiptAhead = $record->received_at?->greaterThan($checkedAt) ?? false;

        return [
            'catalog_checked_at' => $checkedAt->toISOString(),
            'received_at' => $record->received_at,
            'received_age_minutes' => $receipt !== null && ! $receiptAhead ? intdiv($now - $receipt, 60) : null,
            'received_timestamp_evidence' => 'LOCAL_INGESTION_TIME',
            'source_updated_at' => $record->source_updated_at,
            'reported_source_age_minutes' => $source !== null && ! $sourceAhead ? intdiv($now - $source, 60) : null,
            'source_timestamp_evidence' => 'PARTNER_REPORTED_ONLY',
            'source_clock_evidence' => $source === null ? 'SOURCE_TIMESTAMP_UNKNOWN' : ($sourceAhead ? 'PARTNER_CLOCK_AHEAD' : 'PARTNER_REPORTED_TIME'),
        ];
    }
}
