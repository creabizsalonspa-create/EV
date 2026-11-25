<?php

namespace App\Services\Discovery;

final class PublicPartnerLocation
{
    private const REPORTED_STATES = ['AVAILABLE', 'BLOCKED', 'CHARGING', 'INOPERATIVE', 'OUTOFORDER', 'PLANNED', 'REMOVED', 'RESERVED', 'UNKNOWN'];

    private static function text(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $max
            && ! preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : null;
    }

    private static function coordinate(mixed $value, int $max): string|int|float|null
    {
        if (is_string($value)) {
            // Match the bounded SQL viewport/proximity grammar. Normalize only
            // ASCII spaces, never control characters or arbitrary numeric text.
            $value = trim($value, ' ');
            if (preg_match('/^[+-]?([0-9]{1,40}([.][0-9]{0,40})?|[.][0-9]{1,40})([eE][+-]?[0-9]{1,2})?$/D', $value) !== 1) {
                return null;
            }
        }
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || ! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > $max) {
            return null;
        }

        return $value;
    }

    /**
     * Fail-closed public view: even an allowed field name cannot relay a
     * nested private object or a malformed legacy projection.
     */
    public static function project(array $normalized): array
    {
        $location = [];
        foreach (['country_code' => 2, 'party_id' => 3, 'id' => 64, 'last_updated' => 32,
            'name' => 255, 'address' => 255, 'city' => 255, 'country' => 3] as $key => $max) {
            if (array_key_exists($key, $normalized)) {
                $location[$key] = self::text($normalized[$key], $max);
            }
        }
        foreach (['latitude' => 90, 'longitude' => 180] as $key => $max) {
            if (array_key_exists($key, $normalized)) {
                $location[$key] = self::coordinate($normalized[$key], $max);
            }
        }
        $standards = $normalized['connector_standards'] ?? [];
        $location['connector_standards'] = array_values(array_unique(array_filter(array_map(
            static fn ($standard) => self::text($standard, 64),
            is_array($standards) && array_is_list($standards) ? $standards : []
        ), static fn ($standard) => $standard !== null)));
        $evses = $normalized['evses'] ?? [];
        $location['evses'] = [];
        foreach (is_array($evses) && array_is_list($evses) ? array_slice($evses, 0, 100) : [] as $evse) {
            if (! is_array($evse) || ($uid = self::text($evse['uid'] ?? null, 64)) === null) {
                continue;
            }
            $status = $evse['reported_status'] ?? null;
            $connectors = $evse['connectors'] ?? [];
            $publicConnectors = [];
            foreach (is_array($connectors) && array_is_list($connectors) ? array_slice($connectors, 0, 20) : [] as $connector) {
                if (! is_array($connector) || ($id = self::text($connector['id'] ?? null, 64)) === null
                    || ($standard = self::text($connector['standard'] ?? null, 64)) === null) {
                    continue;
                }
                $publicConnectors[] = ['id' => $id, 'standard' => $standard];
            }
            $location['evses'][] = ['uid' => $uid,
                'reported_status' => in_array($status, self::REPORTED_STATES, true) ? $status : 'UNKNOWN',
                'connectors' => $publicConnectors];
        }
        $location['publish'] = true;
        $location['availability'] = 'unknown';
        $location['remote_control'] = false;
        $location['reservable'] = false;

        return $location;
    }
}
