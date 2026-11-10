<?php

namespace App\Services\Discovery;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Read-only, license-filtered OCM discovery. Never inserts canonical charging
 * stations or enables commands, reservations, finance or OCPI authorizations.
 */
final class OpenChargeMapAdapter
{
    private const ORIGIN = 'https://api.openchargemap.io/v3/poi/';

    /**
     * Optional injected transport is solely for deterministic acceptance tests.
     * It receives the bounded API parameters and must return decoded JSON.
     */
    public function __construct(private readonly ?Closure $fixtureTransport = null) {}

    public function search(float $latitude, float $longitude, float $radiusKm, string $key, ?string $country = null, ?float $minPowerKw = null, ?string $connector = null): array
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || ! is_finite($radiusKm)
            || abs($latitude) > 90 || abs($longitude) > 180
            || $radiusKm < 1 || $radiusKm > 200 || trim($key) === ''
            || ($country !== null && preg_match('/^[A-Z]{2}$/D', $country) !== 1)
            || ($minPowerKw !== null && (! is_finite($minPowerKw) || $minPowerKw < 1 || $minPowerKw > 1000))
            || ! self::validOptionalFilter($connector, 80)) {
            throw new LookupFailure('invalid_request', 422);
        }

        $parameters = [
            'output' => 'json',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'distance' => $radiusKm,
            'distanceunit' => 'km',
            'maxresults' => 25,
            'opendata' => 'true',
            'includecomments' => 'false',
            'verbose' => 'true',
            'compact' => 'false',
        ];
        if ($country !== null) {
            $parameters['countrycode'] = $country;
        }
        $records = $this->fixtureTransport !== null
            ? ($this->fixtureTransport)($parameters)
            : $this->fetch($parameters, $key);
        if (! is_array($records) || ! array_is_list($records) || count($records) > 25) {
            throw new LookupFailure('invalid_response');
        }
        $data = [];
        foreach ($records as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $item = OpenChargeMapProjection::item($raw);
            if ($item === null || isset($data[$item['id']])
                || ($country !== null && $item['country'] !== $country)) {
                continue;
            }
            $distance = self::distance($latitude, $longitude, $item['latitude'], $item['longitude']);
            if ($distance > $radiusKm + 0.0001) {
                continue;
            }
            $item['distance_km'] = round($distance, 3);
            $item['distance_basis'] = 'great_circle';
            $data[$item['id']] = $item;
        }
        $values = array_values($data);
        usort($values, static fn (array $a, array $b) => ($a['distance_km'] <=> $b['distance_km']) ?: strcmp($a['id'], $b['id']));

        $source = [
            'provider' => 'open_charge_map',
            'evidence' => 'OPEN_LICENSED_DISCOVERY_ONLY',
            'availability' => 'unknown',
            'remote_control' => false,
            'reservable' => false,
            'billing_eligible' => false,
            'distance_basis' => 'great_circle',
            'license_policy' => 'PER_RECORD_ATTRIBUTION_REQUIRED',
            'count' => count($values),
            'results' => $values,
        ];

        return $this->filterReported($source, $minPowerKw, $connector);
    }

    /**
     * Pure, connection-level source filters over a capped and preauthorized
     * list. Can be run repeatedly on one short-lived cached discovery source.
     * This never upgrades historical reports to live charging privileges.
     */
    public function filterReported(array $source, ?float $minPowerKw = null, ?string $connector = null, ?string $operator = null, ?bool $membership = null, ?bool $operational = null, ?int $maxSourceAgeHours = null): array
    {
        if (($minPowerKw !== null && (! is_finite($minPowerKw) || $minPowerKw < 1 || $minPowerKw > 1000))
            || ! self::validOptionalFilter($connector, 80)
            || ! self::validOptionalFilter($operator, 120)
            || ($maxSourceAgeHours !== null && ($maxSourceAgeHours < 1 || $maxSourceAgeHours > 168))) {
            throw new LookupFailure('invalid_request', 422);
        }
        if (($source['provider'] ?? null) !== 'open_charge_map'
            || ($source['evidence'] ?? null) !== 'OPEN_LICENSED_DISCOVERY_ONLY'
            || ($source['availability'] ?? null) !== 'unknown'
            || ($source['remote_control'] ?? null) !== false
            || ($source['reservable'] ?? null) !== false
            || ($source['billing_eligible'] ?? null) !== false
            || ! is_array($source['results'] ?? null)
            || ! array_is_list($source['results'])
            || count($source['results']) > 25) {
            throw new LookupFailure('invalid_response');
        }
        $values = [];
        $currentTime = class_exists(Carbon::class)
            ? Carbon::now()->getTimestamp()
            : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        foreach ($source['results'] as $item) {
            if (! is_array($item) || ! is_array($item['connections'] ?? null)) {
                throw new LookupFailure('invalid_response');
            }
            // Treat cache storage as untrusted. A malformed cache entry must
            // never advertise actionable OCPP/OCPI capabilities, even when the
            // top-level source envelope still says discovery-only.
            if (($item['provider'] ?? null) !== 'open_charge_map'
                || ($item['source_status_evidence'] ?? null) !== 'OPEN_CHARGE_MAP_DISCOVERY_ONLY'
                || ($item['operator_evidence'] ?? null) !== 'OPEN_CHARGE_MAP_REPORTED_UNVERIFIED'
                || ($item['reported_operational_evidence'] ?? null) !== 'OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED'
                || ($item['reported_access_evidence'] ?? null) !== 'OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED'
                || ($item['reported_cost_evidence'] ?? null) !== 'OPEN_CHARGE_MAP_CROWD_PRICE_UNVERIFIED'
                || ($item['distance_basis'] ?? null) !== 'great_circle'
                || ! self::finiteBounded($item['latitude'] ?? null, 90, true)
                || ! self::finiteBounded($item['longitude'] ?? null, 180, true)
                || ! self::finiteBounded($item['distance_km'] ?? null, 200)
                || ($item['availability'] ?? null) !== 'unknown'
                || ($item['remote_control'] ?? null) !== false
                || ($item['reservable'] ?? null) !== false
                || ($item['billing_eligible'] ?? null) !== false
                || ! is_string($item['data_provider'] ?? null)
                || trim($item['data_provider']) === ''
                || ! is_string($item['data_license'] ?? null)
                || trim($item['data_license']) === ''
                || ! is_string($item['id'] ?? null)
                || preg_match('/^ocm:([1-9][0-9]{0,9})$/D', $item['id'], $idMatch) !== 1
                || (int) ($idMatch[1] ?? 0) > 2147483647
                || ($item['source_url'] ?? null) !== 'https://openchargemap.org/site/poi/details/'.($idMatch[1] ?? '')
                || ($item['attribution'] ?? null) !== 'Open Charge Map / '.$item['data_provider']
                || ! array_is_list($item['connections'])
                || count($item['connections']) > 20) {
                throw new LookupFailure('invalid_response');
            }
            foreach ($item['connections'] as $connection) {
                if (! is_array($connection)
                    || ! is_string($connection['reported_connector_type'] ?? null)
                    || ($connection['availability'] ?? null) !== 'unknown'
                    || (($connection['reported_power_kw'] ?? null) !== null
                        && ! self::finiteBounded($connection['reported_power_kw'], 1000))
                    || array_key_exists('remote_control', $connection)
                    || array_key_exists('reservable', $connection)
                    || array_key_exists('billing_eligible', $connection)) {
                    throw new LookupFailure('invalid_response');
                }
            }
            // The provider response is cached for 5 minutes. Recompute its
            // sourced age for every request, including unfiltered requests,
            // so a recent->stale transition is never frozen in the cache.
            $sourceTime = $item['source_updated_at'] ?? null;
            $seconds = null;
            if (is_string($sourceTime) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $sourceTime) === 1) {
                try {
                    $seconds = (new \DateTimeImmutable($sourceTime))->getTimestamp();
                } catch (\Throwable) {
                    // Unknown/malformed source time never implies freshness.
                }
            }
            $sourceAgeSeconds = $seconds !== null && $seconds <= $currentTime
                ? $currentTime - $seconds : null;
            $item['source_age_minutes'] = $sourceAgeSeconds !== null
                ? intdiv($sourceAgeSeconds, 60) : null;
            $item['source_clock_evidence'] = $seconds === null
                ? 'SOURCE_TIME_UNKNOWN'
                : ($seconds > $currentTime ? 'SOURCE_CLOCK_AHEAD' : 'CROWD_REPORTED_TIME');
            // Filter at exact-second precision. Rounded-down display
            // minutes must never make a 60m59s report satisfy a 1h limit.
            if ($maxSourceAgeHours !== null && ($sourceAgeSeconds === null
                || $sourceAgeSeconds > 3600 * $maxSourceAgeHours)) {
                continue;
            }
            if (($operator !== null && ! self::labelContains((string) ($item['operator_label'] ?? ''), $operator))
                || ($membership !== null && ($item['reported_membership_required'] ?? null) !== $membership)
                || ($operational !== null && ($item['reported_operational'] ?? null) !== $operational)) {
                continue;
            }
            // Source metadata filters do not require a connector.
            if ($minPowerKw === null && $connector === null) {
                $values[] = $item;

                continue;
            }
            foreach ($item['connections'] as $connection) {
                if (! is_array($connection)
                    || ! is_string($connection['reported_connector_type'] ?? null)) {
                    throw new LookupFailure('invalid_response');
                }
                $power = $connection['reported_power_kw'] ?? null;
                if (($minPowerKw === null || (is_numeric($power) && (float) $power >= $minPowerKw))
                    && ($connector === null || self::labelEquals($connection['reported_connector_type'], $connector))) {
                    $values[] = $item;
                    break;
                }
            }
        }

        return self::publicResult($values);
    }

    /**
     * Rebuild the public response from a fixed field list. A cache backend is
     * never an authority for introducing new API fields, even if a nested
     * record retains the approved discovery-only capability flags.
     */
    private static function publicResult(array $values): array
    {
        $stationKeys = [
            'id', 'provider', 'name', 'address', 'city', 'country',
            'latitude', 'longitude', 'operator_label', 'operator_evidence',
            'source_updated_at', 'source_age_minutes', 'source_clock_evidence',
            'source_status_evidence', 'reported_operational',
            'reported_operational_evidence', 'reported_access',
            'reported_membership_required', 'reported_access_key_required',
            'reported_access_evidence', 'reported_cost_note',
            'reported_cost_evidence', 'data_provider', 'data_license',
            'attribution', 'source_url', 'distance_km', 'distance_basis',
            'availability', 'remote_control', 'reservable', 'billing_eligible',
            'connections',
        ];
        $connectionKeys = ['reported_connector_type', 'reported_power_kw', 'availability'];
        $allowedStation = array_fill_keys($stationKeys, true);
        $allowedConnection = array_fill_keys($connectionKeys, true);
        foreach ($values as &$item) {
            foreach ($item['connections'] as &$connection) {
                $connection = array_intersect_key($connection, $allowedConnection);
            }
            unset($connection);
            $item = array_intersect_key($item, $allowedStation);
        }
        unset($item);

        return [
            'provider' => 'open_charge_map',
            'evidence' => 'OPEN_LICENSED_DISCOVERY_ONLY',
            'availability' => 'unknown',
            'remote_control' => false,
            'reservable' => false,
            'billing_eligible' => false,
            'distance_basis' => 'great_circle',
            'license_policy' => 'PER_RECORD_ATTRIBUTION_REQUIRED',
            'count' => count($values),
            'results' => $values,
        ];
    }

    private function fetch(array $parameters, string $key): array
    {
        $stream = null;
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                ->withHeaders(['X-API-Key' => $key, 'User-Agent' => 'CreaBiz-EV-Discovery/1.0'])
                ->withOptions(['stream' => true, 'allow_redirects' => false])
                ->get(self::ORIGIN, $parameters);
            if ($response->status() === 429) {
                throw new LookupFailure('provider_busy', 503);
            }
            if (! $response->successful()) {
                throw new LookupFailure('provider_unavailable', 503);
            }
            $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            if ($type !== 'application/json' || (int) $response->header('Content-Length') > 2097152) {
                throw new LookupFailure('invalid_response');
            }
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(min(16384, 2097152 - strlen($body) + 1));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new LookupFailure('provider_unavailable', 503);
                }
                $body .= $chunk;
                if (strlen($body) > 2097152) {
                    throw new LookupFailure('invalid_response');
                }
            }
            $json = json_decode($body, true, 48, JSON_THROW_ON_ERROR);
            if (! is_array($json) || ! array_is_list($json)) {
                throw new LookupFailure('invalid_response');
            }

            return $json;
        } catch (LookupFailure $exception) {
            throw $exception;
        } catch (\Throwable) {
            // HTTP exceptions may embed coordinates, API keys or raw provider
            // addresses. Never expose them to a requester or application log.
            throw new LookupFailure('provider_unavailable', 503);
        } finally {
            try {
                $stream?->close();
            } catch (\Throwable) {
                // Connection cleanup must not expose upstream details.
            }
        }
    }

    /** A cached map coordinate or reported rating must be a finite numeric scalar. */
    private static function finiteBounded(mixed $value, float $maximum, bool $signed = false): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && ($signed ? abs((float) $value) <= $maximum : ($value >= 0 && $value <= $maximum));
    }

    private static function validOptionalFilter(?string $value, int $maximum): bool
    {
        if ($value === null) {
            return true;
        }
        if ($value === '' || trim($value) !== $value
            || preg_match('/[\x00-\x1F\x7F]/u', $value) !== 0) {
            return false;
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($characters) && count($characters) <= $maximum;
    }

    private static function labelContains(string $label, string $literal): bool
    {
        // The user term is always escaped: never interpret supplier names or
        // search input as a regex. Unicode case-folding works without mbstring.
        return preg_match('/'.preg_quote($literal, '/').'/iu', $label) === 1;
    }

    private static function labelEquals(string $label, string $literal): bool
    {
        return preg_match('/\A'.preg_quote($literal, '/').'\z/iu', $label) === 1;
    }

    private static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthKm = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthKm * asin(sqrt(max(0, min(1, $a))));
    }
}
