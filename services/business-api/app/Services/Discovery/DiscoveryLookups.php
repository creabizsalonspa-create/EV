<?php

namespace App\Services\Discovery;

use Illuminate\Support\Facades\Http;

final class DiscoveryLookups
{
    public function available(string $feature): bool
    {
        $key = config('discovery.geoapify_key');

        return config('discovery.'.$feature.'_enabled') === true && is_string($key) && trim($key) !== '' && strlen($key) <= 512;
    }

    public function capabilities(): array
    {
        return ['provider' => 'geoapify', 'geocoding' => $this->available('geocoding'), 'routing' => $this->available('routing'), 'max_charging_stops' => 3, 'privacy_url' => 'https://www.geoapify.com/privacy-policy/'];
    }

    private function fetch(string $feature, string $path, array $query, int $limit): array
    {
        if (! $this->available($feature)) {
            throw new LookupFailure('not_configured', 503);
        }
        $stream = null;
        try {
            // Fixed origin; no configurable URLs, redirects, retries, cookies or user tokens.
            $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->withOptions(['stream' => true, 'allow_redirects' => false])->get('https://api.geoapify.com'.$path, $query + ['apiKey' => config('discovery.geoapify_key')]);
            $stream = $response->toPsrResponse()->getBody();
            if ($response->status() === 429) {
                throw new LookupFailure('provider_busy', 503);
            }
            if (in_array($response->status(), [400, 404], true)) {
                throw new LookupFailure('not_found', 422);
            }
            if (! $response->successful()) {
                throw new LookupFailure('provider_unavailable', 503);
            }
            $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            if (! in_array($type, ['application/json', 'application/geo+json'], true) || (int) $response->header('Content-Length') > $limit) {
                throw new LookupFailure('invalid_response');
            }
            $body = '';
            while (! $stream->eof()) {
                $chunk = $stream->read(min(16384, $limit - strlen($body) + 1));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new LookupFailure('provider_unavailable', 503);
                }
                $body .= $chunk;
                if (strlen($body) > $limit) {
                    throw new LookupFailure('response_too_large');
                }
            }
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['type'] ?? null) !== 'FeatureCollection' || ! is_array($data['features'] ?? null) || ! array_is_list($data['features'])) {
                throw new LookupFailure('invalid_response');
            }

            return $data;
        } catch (LookupFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            // Upstream exceptions can contain query addresses and the API key: never expose/report them.
            throw new LookupFailure('provider_unavailable', 503);
        } finally {
            try {
                $stream?->close();
            } catch (\Throwable) {
                // Closing a failed upstream stream must not expose its exception.
            }
        }
    }

    private function point(mixed $coordinates): array
    {
        if (! is_array($coordinates) || ! array_is_list($coordinates) || count($coordinates) < 2 || count($coordinates) > 3 || ! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1])) {
            throw new LookupFailure('invalid_response');
        }
        [$lng, $lat] = [(float) $coordinates[0], (float) $coordinates[1]];
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            throw new LookupFailure('invalid_response');
        }

        return ['latitude' => $lat, 'longitude' => $lng];
    }

    public function addresses(array $input): array
    {
        $query = ['text' => $input['query'], 'lang' => $input['language'], 'limit' => 5, 'format' => 'geojson'];
        if ($input['country'] ?? null) {
            $query['filter'] = 'countrycode:'.strtolower($input['country']);
        }
        $data = $this->fetch('geocoding', '/v1/geocode/autocomplete', $query, 1048576);
        if (count($data['features']) > 20) {
            throw new LookupFailure('invalid_response');
        }
        $results = [];
        foreach ($data['features'] as $feature) {
            if (($feature['geometry']['type'] ?? null) !== 'Point' || ! is_string($feature['properties']['formatted'] ?? null) || trim($feature['properties']['formatted']) === '') {
                throw new LookupFailure('invalid_response');
            }
            $point = $this->point($feature['geometry']['coordinates'] ?? null);
            $label = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $feature['properties']['formatted']), 0, 300);
            $id = 'geoapify:'.hash('sha256', json_encode([$point, $label], JSON_THROW_ON_ERROR));
            $results[$id] = ['id' => $id, 'label' => $label, ...$point];
        }

        return ['provider' => 'geoapify', 'evidence' => 'GEOCODING_RESULT', 'places' => array_slice(array_values($results), 0, 5)];
    }

    public function route(array $input): array
    {
        $query = ['waypoints' => implode('|', array_map(fn ($p) => $p['latitude'].','.$p['longitude'], $input['points'])), 'mode' => 'drive', 'units' => 'metric', 'lang' => $input['language']];
        $data = $this->fetch('routing', '/v1/routing', $query, 2097152);
        if (count($data['features']) === 0) {
            throw new LookupFailure('not_found', 422);
        }
        if (count($data['features']) !== 1) {
            throw new LookupFailure('invalid_response');
        }
        $feature = $data['features'][0];
        $properties = $feature['properties'] ?? [];
        foreach (['distance' => 40000000, 'time' => 31536000] as $key => $maximum) {
            if (! isset($properties[$key]) || ! is_numeric($properties[$key]) || ! is_finite((float) $properties[$key]) || $properties[$key] < 0 || $properties[$key] > $maximum) {
                throw new LookupFailure('invalid_response');
            }
        }
        if (($properties['distance_units'] ?? 'meters') !== 'meters' || ($properties['time_units'] ?? 'seconds') !== 'seconds') {
            throw new LookupFailure('invalid_response');
        }
        $geometry = $feature['geometry'] ?? [];
        $lines = match ($geometry['type'] ?? null) {
            'LineString' => [$geometry['coordinates'] ?? null],
            'MultiLineString' => $geometry['coordinates'] ?? null,
            default => null,
        };
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) === 0 || count($lines) > 100) {
            throw new LookupFailure('invalid_response');
        }
        $segments = [];
        $count = 0;
        foreach ($lines as $line) {
            if (! is_array($line) || ! array_is_list($line) || count($line) < 2 || ($count += count($line)) > 10000) {
                throw new LookupFailure('response_too_large');
            }
            $segments[] = array_map(fn ($p) => $this->point($p), $line);
        }

        return ['provider' => 'geoapify', 'evidence' => 'PROVIDER_ROUTE_ESTIMATE', 'distance_meters' => (float) $properties['distance'], 'duration_seconds' => (float) $properties['time'], 'traffic_verified' => false, 'charging_time_included' => false, 'segments' => $segments, 'attribution' => 'Routing © Geoapify / OpenStreetMap contributors'];
    }
}
