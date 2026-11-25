<?php

namespace App\Services\Discovery;

use App\Models\OcpiRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Catalog timestamps describe reports, never physical connector availability. */
final class PartnerCatalogAge
{
    /** Decode persisted UTC instants without allowing relative or rolled-over dates. */
    public static function storedInstant(OcpiRecord $record, string $field): ?CarbonImmutable
    {
        $value = $record->getAttributes()[$field] ?? null;
        if (! is_string($value)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T]([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.(\d{1,6}))?Z?$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function elapsedMinutes(?CarbonImmutable $instant, CarbonInterface $checkedAt): ?int
    {
        if ($instant === null || $instant->greaterThan($checkedAt)) {
            return null;
        }
        $seconds = $checkedAt->getTimestamp() - $instant->getTimestamp();
        if ((int) $checkedAt->format('u') < (int) $instant->format('u')) {
            $seconds--;
        }

        return intdiv($seconds, 60);
    }

    public static function present(OcpiRecord $record, CarbonInterface $checkedAt): array
    {
        $source = self::storedInstant($record, 'source_updated_at');
        $receipt = self::storedInstant($record, 'received_at');
        $sourceAhead = $source?->greaterThan($checkedAt) ?? false;

        return [
            'catalog_age_policy' => PartnerCatalogPolicy::present($record, $checkedAt),
            'catalog_checked_at' => $checkedAt->toISOString(),
            'received_at' => $receipt,
            'received_age_minutes' => self::elapsedMinutes($receipt, $checkedAt),
            'received_timestamp_evidence' => 'LOCAL_INGESTION_TIME',
            'source_updated_at' => $source,
            'reported_source_age_minutes' => self::elapsedMinutes($source, $checkedAt),
            'source_timestamp_evidence' => 'PARTNER_REPORTED_ONLY',
            'source_clock_evidence' => $source === null ? 'SOURCE_TIMESTAMP_UNKNOWN' : ($sourceAhead ? 'PARTNER_CLOCK_AHEAD' : 'PARTNER_REPORTED_TIME'),
        ];
    }
}
