<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PartnerClient
{
    public const MODULES = ['locations', 'tariffs', 'sessions', 'cdrs'];

    public function connection(OcpiProvider $provider): array
    {
        if (! $provider->authorized) {
            throw new OcpiException('Operator authorization is required.');
        }
        $c = config('ocpi.connections.'.$provider->connection_key);
        if (! is_array($c) || ! is_string($c['origin'] ?? null) || ! is_string($c['authorization_token'] ?? null) || $c['authorization_token'] === '') {
            throw new OcpiException('Approved operator connection is not configured.');
        }
        $origin = rtrim($c['origin'], '/');
        $parts = parse_url($origin);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['port'] ?? 443, [443], true) || ! in_array($parts['path'] ?? '', ['', '/'], true) || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $host) || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid)$/', $host)) {
            throw new OcpiException('Operator origin must be an approved public HTTPS DNS origin.');
        }
        if (strpbrk($c['authorization_token'], "\r\n") !== false) {
            throw new OcpiException('Invalid operator credential format.');
        }

        return $c + ['origin' => $origin];
    }

    public function guardedUrl(OcpiProvider $provider, string $url): string
    {
        $c = $this->connection($provider);
        $origin = rtrim($c['origin'], '/');
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = $origin.$url;
        }
        $parts = parse_url($url);
        $approved = parse_url($origin);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== strtolower($approved['host']) || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new OcpiException('Operator endpoint escaped the approved HTTPS origin.');
        }

        return $url;
    }

    private function assertCurrent(OcpiProvider $provider): void
    {
        $current = OcpiProvider::find($provider->id);
        if (! $current || ! $current->authorized || $current->authorization_revision !== $provider->authorization_revision || $current->connection_key !== $provider->connection_key || $current->owner_id !== $provider->owner_id) {
            throw new OcpiException('Operator authorization changed; refresh and negotiate again.');
        }
    }

    private function get(OcpiProvider $provider, string $url): array
    {
        // An already dispatched read cannot be cancelled, but a revoked or superseded
        // operation cannot dispatch another page or commit a stale result.
        $this->assertCurrent($provider);
        $url = $this->guardedUrl($provider, $url);
        $c = $this->connection($provider);
        try {
            $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Token '.$c['authorization_token'], 'X-Request-ID' => (string) Str::uuid(), 'X-Correlation-ID' => (string) Str::uuid()])->get($url);
            if (! $response->successful()) {
                throw new OcpiException('Operator HTTP request failed.');
            }
            $stream = $response->toPsrResponse()->getBody();
            $text = '';
            try {
                while (! $stream->eof() && strlen($text) <= 2_000_000) {
                    $text .= $stream->read(min(8192, 2_000_001 - strlen($text)));
                }
            } finally {
                $stream->close();
            }
            if (strlen($text) > 2_000_000) {
                throw new OcpiException('Operator response exceeded the accepted size.');
            }
            $body = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($body) || ($body['status_code'] ?? null) !== 1000 || ! array_key_exists('data', $body) || ! is_string($body['timestamp'] ?? null) || strtotime($body['timestamp']) === false) {
                throw new OcpiException('Operator OCPI response was unsuccessful or malformed.');
            }

            return ['data' => $body['data'], 'next' => $this->next($response->header('Link'))];
        } catch (OcpiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OcpiException('Operator request failed; credentials and upstream response are withheld.');
        }
    }

    private function next(?string $link): ?string
    {
        if (! $link) {
            return null;
        }

        return preg_match('/<([^>]+)>;\s*rel="?next"?/i', $link, $m) ? $m[1] : null;
    }

    public function negotiate(OcpiProvider $provider): array
    {
        $c = $this->connection($provider);
        $versions = $this->get($provider, $c['versions_path'] ?? '/ocpi/versions')['data'];
        if (! is_array($versions) || ! array_is_list($versions)) {
            throw new OcpiException('Malformed operator versions response.');
        }
        $selected = null;
        foreach ($versions as $version) {
            if (is_array($version) && ($version['version'] ?? null) === '2.2.1') {
                if ($selected !== null) {
                    throw new OcpiException('Ambiguous duplicate OCPI 2.2.1 version advertisement.');
                }
                $selected = $version;
            }
        }
        if (! $selected || ! is_string($selected['url'] ?? null)) {
            throw new OcpiException('Operator does not advertise OCPI 2.2.1.');
        }
        $details = $this->get($provider, $selected['url'])['data'];
        if (! is_array($details) || ($details['version'] ?? null) !== '2.2.1' || ! is_array($details['endpoints'] ?? null)) {
            throw new OcpiException('Malformed OCPI 2.2.1 details.');
        }
        $endpoints = [];
        foreach ($details['endpoints'] as $endpoint) {
            if (! is_array($endpoint) || ! is_string($endpoint['identifier'] ?? null) || ! is_string($endpoint['url'] ?? null) || ! in_array($endpoint['role'] ?? null, ['SENDER', 'RECEIVER'], true)) {
                throw new OcpiException('Malformed module endpoint.');
            }
            $url = $this->guardedUrl($provider, $endpoint['url']);
            if (in_array($endpoint['identifier'], self::MODULES, true) && $endpoint['role'] === 'SENDER') {
                if (isset($endpoints[$endpoint['identifier']])) {
                    throw new OcpiException('Ambiguous duplicate operator module endpoint.');
                }
                $endpoints[$endpoint['identifier']] = $url;
            }
        }
        if (! isset($endpoints['locations'])) {
            throw new OcpiException('Operator does not advertise a readable locations module.');
        }
        DB::transaction(function () use ($provider, $endpoints) {
            $locked = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            if (! $locked->authorized || $locked->authorization_revision !== $provider->authorization_revision || $locked->connection_key !== $provider->connection_key || $locked->owner_id !== $provider->owner_id) {
                throw new OcpiException('Operator authorization changed; stale negotiation was discarded.');
            }
            if ($locked->authorization_revision >= PHP_INT_MAX) {
                throw new OcpiException('Operator authorization revision is exhausted.');
            }
            $locked->update(['version' => '2.2.1', 'endpoints' => $endpoints, 'negotiated_at' => now(), 'authorization_revision' => $locked->authorization_revision + 1]);
            $provider->setRawAttributes($locked->getAttributes(), true);
        }, 3);

        return $endpoints;
    }

    public function pull(OcpiProvider $provider, string $module): array
    {
        $this->assertCurrent($provider);
        if (! in_array($module, self::MODULES, true) || $provider->version !== '2.2.1' || ! is_string($provider->endpoints[$module] ?? null)) {
            throw new OcpiException('Requested module has not been negotiated.');
        }
        $url = $provider->endpoints[$module];
        $records = [];
        $seen = [];
        for ($page = 0; $page < (int) config('ocpi.max_pages', 20); $page++) {
            $url = $this->guardedUrl($provider, $url);
            if (isset($seen[$url])) {
                throw new OcpiException('Operator pagination loop detected.');
            }$seen[$url] = true;
            $result = $this->get($provider, $url);
            if (! is_array($result['data']) || ! array_is_list($result['data'])) {
                throw new OcpiException('Module response must contain a record list.');
            }
            $records = array_merge($records, $result['data']);
            if (count($records) > (int) config('ocpi.max_records', 1000)) {
                throw new OcpiException('Operator synchronization exceeded the configured record limit.');
            }
            if ($result['next'] === null) {
                return $records;
            }$url = $result['next'];
        }
        throw new OcpiException('Operator pagination exceeded the configured page limit.');
    }
}
