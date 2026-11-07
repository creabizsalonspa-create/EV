<?php

namespace App\Services\Discovery;

use DateTimeImmutable;
use DateTimeZone;

/**
 * An allowlisted, discovery-only projection of an Open Charge Map POI.
 * Never turns crowd-reported status or station ownership into charging authority.
 * Callers must enforce OCM API credentials, licensing policy and attribution.
 */
final class OpenChargeMapProjection
{
    /** @return array<string, mixed>|null */
    public static function item(array $poi, ?DateTimeImmutable $asOf = null): ?array
    {
        $id = $poi['ID'] ?? null;
        if (! is_int($id) || $id < 1 || $id > 2147483647) {
            return null;
        }

        $provider = $poi['DataProvider'] ?? null;
        if (! is_array($provider) || ($provider['IsOpenDataLicensed'] ?? null) !== true) {
            return null;
        }
        if (($provider['DataProviderStatusType']['IsProviderEnabled'] ?? true) === false) {
            return null;
        }
        $license = self::label($provider['License'] ?? null, 240);
        if ($license === '') {
            return null;
        }
        // Refuse unpublished entries if the upstream describes their state.
        if (isset($poi['SubmissionStatusTypeID']) && (int) $poi['SubmissionStatusTypeID'] !== 200) {
            return null;
        }
        if (isset($poi['SubmissionStatus']['IsLive']) && $poi['SubmissionStatus']['IsLive'] !== true) {
            return null;
        }
        $address = $poi['AddressInfo'] ?? null;
        if (! is_array($address)) {
            return null;
        }
        $latitude = self::coordinate($address['Latitude'] ?? null, 90);
        $longitude = self::coordinate($address['Longitude'] ?? null, 180);
        if ($latitude === null || $longitude === null) {
            return null;
        }
        $operator = self::label($poi['OperatorInfo']['Title'] ?? null, 120);
        $providerLabel = self::label($provider['Title'] ?? null, 120);
        if ($providerLabel === '') {
            // A license is mandatory, but an unlabelled provider is not
            // promoted to an officially certified operator.
            $providerLabel = 'Open Charge Map contributor (name unverified)';
        }
        $now = $asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sourceTime = self::date($poi['DateLastStatusUpdate'] ?? $poi['DateLastConfirmed'] ?? null);
        $sourceAge = $sourceTime === null || $sourceTime->getTimestamp() > $now->getTimestamp()
            ? null
            : intdiv($now->getTimestamp() - $sourceTime->getTimestamp(), 60);
        $sourceEvidence = $sourceTime === null ? 'SOURCE_TIME_UNKNOWN'
            : ($sourceTime->getTimestamp() > $now->getTimestamp() ? 'SOURCE_CLOCK_AHEAD' : 'CROWD_REPORTED_TIME');

        $connections = [];
        foreach (is_array($poi['Connections'] ?? null) ? $poi['Connections'] : [] as $connection) {
            if (! is_array($connection) || count($connections) >= 20) {
                continue;
            }
            $type = self::label($connection['ConnectionType']['Title'] ?? null, 80);
            $power = self::coordinate($connection['PowerKW'] ?? null, 1000);
            // OCM connection type is a descriptive label, not an authorized
            // OCPI connector ID or a promise that hardware is available.
            if ($type !== '') {
                $connections[] = [
                    'reported_connector_type' => $type,
                    'reported_power_kw' => $power !== null && $power >= 0 ? $power : null,
                    'availability' => 'unknown',
                ];
            }
        }

        return [
            'id' => 'ocm:'.$id,
            'provider' => 'open_charge_map',
            'name' => self::label($address['Title'] ?? null, 160) ?: 'Charging location (unverified)',
            'address' => self::label($address['AddressLine1'] ?? null, 160),
            'city' => self::label($address['Town'] ?? null, 100),
            'country' => self::label($address['Country']['ISOCode'] ?? null, 2),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'operator_label' => $operator,
            'operator_evidence' => 'OPEN_CHARGE_MAP_REPORTED_UNVERIFIED',
            'connections' => $connections,
            'source_updated_at' => $sourceTime?->format('Y-m-d\TH:i:s\Z'),
            'source_age_minutes' => $sourceAge,
            'source_clock_evidence' => $sourceEvidence,
            'source_status_evidence' => 'OPEN_CHARGE_MAP_DISCOVERY_ONLY',
            'data_provider' => $providerLabel,
            'data_license' => $license,
            'attribution' => 'Open Charge Map / '.$providerLabel,
            'source_url' => 'https://openchargemap.org/site/poi/details/'.$id,
            'availability' => 'unknown',
            'remote_control' => false,
            'reservable' => false,
            'billing_eligible' => false,
        ];
    }

    private static function label(mixed $value, int $limit): string
    {
        if (! is_string($value)) {
            return '';
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim($value));

        return is_string($clean) ? (function_exists('mb_substr') ? mb_substr($clean, 0, $limit) : implode('', array_slice(preg_split('//u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, $limit))) : '';
    }

    private static function coordinate(mixed $value, int $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && abs($number) <= $maximum ? $number : null;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || strlen($value) > 64 || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/i', trim($value))) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
