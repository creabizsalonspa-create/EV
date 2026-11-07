<?php

namespace App\Services\Discovery;

use Closure;
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
            || ($connector !== null && (strlen($connector) < 1 || strlen($connector) > 80
                || trim($connector) !== $connector || preg_match('/[\x00-\x1F\x7F]/', $connector)))) {
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
            // These filters apply to the bounded 25 returned provider records.
            // All metadata is historic/partner-reported, never live capability.
            // Both predicates must match the SAME reported connection.
            if ($minPowerKw !== null || $connector !== null) {
                $matched = false;
                foreach ($item['connections'] as $connection) {
                    $power = $connection['reported_power_kw'];
                    if (($minPowerKw === null || ($power !== null && $power >= $minPowerKw))
                        && ($connector === null || strcasecmp($connection['reported_connector_type'], $connector) === 0)) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    continue;
                }
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
