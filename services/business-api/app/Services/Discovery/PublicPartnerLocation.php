<?php

namespace App\Services\Discovery;

final class PublicPartnerLocation
{
    /**
     * Fail-closed public view: normalizers can evolve with private OCPI fields.
     * This endpoint never relays arbitrary normalized or nested connector data.
     */
    public static function project(array $normalized): array
    {
        $keys = [
            'country_code', 'party_id', 'id', 'last_updated', 'name',
            'address', 'city', 'country', 'latitude', 'longitude',
            'connector_standards',
        ];
        $location = array_intersect_key($normalized, array_fill_keys($keys, true));
        $evses = $normalized['evses'] ?? [];
        $location['evses'] = array_values(array_map(static function (array $evse) {
            $connectors = $evse['connectors'] ?? [];

            return [
                'uid' => $evse['uid'] ?? null,
                'reported_status' => $evse['reported_status'] ?? 'UNKNOWN',
                'connectors' => array_values(array_map(
                    static fn (array $connector) => array_intersect_key(
                        $connector, ['id' => true, 'standard' => true]
                    ),
                    array_filter(is_array($connectors) ? $connectors : [], 'is_array')
                )),
            ];
        }, array_filter(is_array($evses) ? $evses : [], 'is_array')));
        $location['publish'] = true;
        $location['availability'] = 'unknown';
        $location['remote_control'] = false;
        $location['reservable'] = false;

        return $location;
    }
}
