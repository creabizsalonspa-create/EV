<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;

/**
 * Exact country filters for the initially supported operational markets.
 *
 * Platform stations store ISO 3166-1 alpha-2; normalized OCPI *location*
 * metadata stores alpha-3. The OCPI provider's country_code identifies its
 * party and MUST NOT be used as the charging location's country.
 * Unknown translations fail closed at request validation, never silently
 * exclude partner records or confuse a CPO's country with station location.
 */
final class UnifiedCountryFilter
{
    public const MARKET_CODES = ['AE' => 'ARE', 'CA' => 'CAN', 'US' => 'USA'];

    public static function apply(Builder $catalog, Builder $partner, string $country): void
    {
        if (! isset(self::MARKET_CODES[$country])) {
            throw new \InvalidArgumentException('Unsupported discovery market.');
        }

        $catalog->where('stations.country', $country);
        $partner->where('normalized->country', self::MARKET_CODES[$country]);
    }
}
