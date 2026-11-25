<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PartnerClient
{
    private const PROTOCOL_RESPONSE_BYTES = 65_536;

    // Reconciliation reads can return the full catalog record inside an OCPI envelope.
    private const CATALOG_READ_RESPONSE_BYTES = CpoCatalogContract::MAX_RECORD_BYTES + 8_192;

    public const MODULES = ['locations', 'tariffs', 'sessions', 'cdrs'];

    public function connection(OcpiProvider $provider, bool $bootstrap = false): array
    {
        if (! $provider->authorized) {
            throw new OcpiException('Operator authorization is required.');
        }
        $c = config('ocpi.connections.'.$provider->connection_key);
        if (! is_array($c) || ! is_string($c['origin'] ?? null)) {
            throw new OcpiException('Approved operator connection is not configured.');
        }
        $incoming = ($c['managed_credentials'] ?? false) && ($c['registration_direction'] ?? 'outbound') === 'incoming';
        $configuredCredential = $incoming ? ($c['inbound_authorization_token'] ?? null) : ($c['authorization_token'] ?? null);
        if (! is_string($configuredCredential) || $configuredCredential === '') {
            throw new OcpiException('Approved operator connection is not configured.');
        }
        $origin = rtrim($c['origin'], '/');
        $parts = parse_url($origin);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['port'] ?? 443, [443], true) || ! in_array($parts['path'] ?? '', ['', '/'], true) || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $host) || filter_var($host, FILTER_VALIDATE_IP) || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid)$/', $host)) {
            throw new OcpiException('Operator origin must be an approved public HTTPS DNS origin.');
        }
        if (strpbrk($configuredCredential, "\r\n") !== false) {
            throw new OcpiException('Invalid operator credential format.');
        }

        if (($c['managed_credentials'] ?? false) && ! $bootstrap && $provider->exists) {
            $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->first();
            if (! $link) {
                throw new OcpiException('Managed partner credentials must be exchanged before data synchronization.');
            }
            if ($link) {
                if ($link->state !== 'active' || $link->owner_id !== $provider->owner_id || ! hash_equals($link->configuration_hash, app(CredentialExchange::class)->configurationHash($provider)) || ! $link->remote_token_ciphertext) {
                    throw new OcpiException('Managed partner credentials require reconciliation.');
                }
                $c['authorization_token'] = base64_encode(Crypt::decryptString($link->remote_token_ciphertext));
            }
        }

        return $c + ['origin' => $origin];
    }

    public function guardedUrl(OcpiProvider $provider, string $url): string
    {
        $c = $this->connection($provider, true);
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

    /** Reject contradictory peer tracing without breaking previously approved headerless sandbox fixtures. */
    private function verifyTrace(Response $response, array $sent): void
    {
        $headers = $response->toPsrResponse();
        $hasRequest = $headers->hasHeader('X-Request-ID');
        $hasCorrelation = $headers->hasHeader('X-Correlation-ID');
        if (! $hasRequest && ! $hasCorrelation) {
            // Legacy headerless fixtures are permitted only in local/testing.
            // Real OCPI partners must echo both outbound tracing identifiers.
            if (app()->environment(['local', 'testing'])) {
                return;
            }
            throw new OcpiException('Operator OCPI tracing headers disagreed with this request.');
        }
        if (! $hasRequest || ! $hasCorrelation
            || ! hash_equals($sent['X-Request-ID'], $headers->getHeaderLine('X-Request-ID'))
            || ! hash_equals($sent['X-Correlation-ID'], $headers->getHeaderLine('X-Correlation-ID'))) {
            throw new OcpiException('Operator OCPI tracing headers disagreed with this request.');
        }
    }

    /** OCPI envelopes have the same strict UTC DateTime contract as source records. */
    private function validEnvelopeTimestamp(mixed $value): bool
    {
        if (! is_string($value)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z?$/D', $value)) {
            return false;
        }
        $format = '!Y-m-d\TH:i:s'
            .(str_contains($value, '.') ? '.u' : '')
            .(str_ends_with($value, 'Z') ? '\Z' : '');
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));

        return $date !== false && \DateTimeImmutable::getLastErrors() === false;
    }

    private function get(OcpiProvider $provider, string $url): array
    {
        // An already dispatched read cannot be cancelled, but a revoked or superseded
        // operation cannot dispatch another page or commit a stale result.
        $this->assertCurrent($provider);
        $url = $this->guardedUrl($provider, $url);
        $c = $this->connection($provider);
        try {
            $trace = ['X-Request-ID' => (string) Str::uuid(), 'X-Correlation-ID' => (string) Str::uuid()];
            $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Token '.$c['authorization_token']] + $trace)->get($url);
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
            $this->verifyTrace($response, $trace);
            $body = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($body) || ($body['status_code'] ?? null) !== 1000 || ! array_key_exists('data', $body) || ! $this->validEnvelopeTimestamp($body['timestamp'] ?? null)) {
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

        preg_match_all('/<([^>]+)>;\s*rel=(?:"next"|next)(?=\s*[,;]|\s*$)/i', $link, $matches);
        if (count($matches[1]) > 1) {
            throw new OcpiException('Ambiguous partner pagination links require source review.');
        }

        return $matches[1][0] ?? null;
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
        $cpo = app(CpoCatalogContract::class)->modules($provider);
        foreach ($details['endpoints'] as $endpoint) {
            if (! is_array($endpoint) || ! is_string($endpoint['identifier'] ?? null) || ! is_string($endpoint['url'] ?? null) || ! in_array($endpoint['role'] ?? null, ['SENDER', 'RECEIVER'], true)) {
                throw new OcpiException('Malformed module endpoint.');
            }
            $url = $this->guardedUrl($provider, $endpoint['url']);
            if (($c['test_commands'] ?? false) === true && in_array($endpoint['identifier'], ['tokens', 'commands'], true) && $endpoint['role'] === 'RECEIVER') {
                $name = $endpoint['identifier'].'_receiver';
                if (isset($endpoints[$name])) {
                    throw new OcpiException('Ambiguous sandbox command module endpoint.');
                }
                $endpoints[$name] = $url;
            }
            if (in_array($endpoint['identifier'], $cpo, true) && $endpoint['role'] === 'RECEIVER') {
                $name = $endpoint['identifier'].'_receiver';
                if (isset($endpoints[$name]) || parse_url($url, PHP_URL_QUERY) !== null) {
                    throw new OcpiException('Ambiguous CPO catalog receiver endpoint.');
                }
                $endpoints[$name] = $url;
            }
            if (in_array($endpoint['identifier'], self::MODULES, true) && $endpoint['role'] === 'SENDER') {
                if (isset($endpoints[$endpoint['identifier']])) {
                    throw new OcpiException('Ambiguous duplicate operator module endpoint.');
                }
                $endpoints[$endpoint['identifier']] = $url;
            }
        }
        if (! isset($endpoints['locations']) && ! ($cpo && isset($endpoints['locations_receiver'], $endpoints['tariffs_receiver']))) {
            throw new OcpiException('Operator lacks the approved OCPI catalog modules.');
        }
        DB::transaction(function () use ($provider, $endpoints) {
            // Negotiation performs outbound I/O before this transaction. Pin the
            // owner state before provider publication, so a vendor suspended
            // during the peer response cannot leave an apparently valid catalog.
            $owner = User::whereKey($provider->owner_id)->lockForUpdate()->first();
            $locked = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            if (! $owner || $owner->role !== 'vendor' || $owner->account_status !== 'active'
                || ! $locked->authorized || $locked->authorization_revision !== $provider->authorization_revision
                || $locked->connection_key !== $provider->connection_key || $locked->owner_id !== $provider->owner_id) {
                throw new OcpiException('Operator authorization or owner activity changed; stale negotiation was discarded.');
            }
            if ($locked->authorization_revision >= PHP_INT_MAX) {
                throw new OcpiException('Operator authorization revision is exhausted.');
            }
            $locked->update(['version' => '2.2.1', 'endpoints' => $endpoints, 'negotiated_at' => now(), 'authorization_revision' => $locked->authorization_revision + 1]);
            $provider->setRawAttributes($locked->getAttributes(), true);
        }, 3);

        return $endpoints;
    }

    /** Explicit read-only OCPI date windows; never silently interpret server-local dates. */
    private function pullWindow(array $requested): array
    {
        if (array_diff(array_keys($requested), ['date_from', 'date_to'])) {
            throw new OcpiException('Unsupported OCPI source window parameter.');
        }
        $window = [];
        $instants = [];
        foreach (['date_from', 'date_to'] as $field) {
            if (! array_key_exists($field, $requested)) {
                continue;
            }
            $value = $requested[$field];
            if (! is_string($value) || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)?$/D', $value)) {
                throw new OcpiException('Invalid OCPI source window timestamp.');
            }
            // OCPI 2.2.1 §16.2: absent Z means UTC. Internal callers may
            // provide offsets, but the peer always receives canonical UTC.
            $hasZone = str_ends_with($value, 'Z') || preg_match('/[+-]\\d{2}:\\d{2}$/D', $value);
            $format = '!Y-m-d\\TH:i:s'.(str_contains($value, '.') ? '.u' : '').($hasZone ? 'P' : '');
            $instant = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            if (! $instant || \DateTimeImmutable::getLastErrors() !== false) {
                throw new OcpiException('Invalid OCPI source window timestamp.');
            }
            $instants[$field] = $instant;
            $window[$field] = $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
        }
        if (isset($instants['date_from'], $instants['date_to']) && $instants['date_from'] >= $instants['date_to']) {
            throw new OcpiException('OCPI source window must have a later exclusive end.');
        }

        return $window;
    }

    /** A requested date window must match the returned source evidence too. */
    private function verifyWindowRecords(array $records, array $window): void
    {
        if ($window === []) {
            return;
        }
        $from = isset($window['date_from']) ? new \DateTimeImmutable($window['date_from']) : null;
        $to = isset($window['date_to']) ? new \DateTimeImmutable($window['date_to']) : null;
        foreach ($records as $record) {
            $value = is_array($record) ? ($record['last_updated'] ?? null) : null;
            if (! is_string($value)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z?$/D', $value)) {
                throw new OcpiException('Partner source record lacks a valid OCPI window timestamp.');
            }
            $format = '!Y-m-d\TH:i:s'
                .(str_contains($value, '.') ? '.u' : '')
                .(str_ends_with($value, 'Z') ? '\Z' : '');
            $instant = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            if (! $instant || \DateTimeImmutable::getLastErrors() !== false
                || ($from !== null && $instant < $from)
                || ($to !== null && $instant >= $to)) {
                throw new OcpiException('Partner source record violated the requested OCPI date window.');
            }
        }
    }

    /** Refuse a peer pagination link that silently broadens an explicit source window. */
    private function verifyWindowNext(string $url, array $window): void
    {
        if ($window === []) {
            return;
        }
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new OcpiException('Malformed OCPI source-window pagination link.');
        }
        $observed = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode($name);
            if (! in_array($name, ['date_from', 'date_to'], true)) {
                continue;
            }
            if (array_key_exists($name, $observed)) {
                throw new OcpiException('Ambiguous OCPI source-window pagination link.');
            }
            $observed[$name] = rawurldecode($value);
        }
        if (count($observed) !== count($window)) {
            throw new OcpiException('OCPI source-window pagination lost its bounds.');
        }
        foreach ($window as $field => $value) {
            if (! isset($observed[$field]) || ! hash_equals($value, $observed[$field])) {
                throw new OcpiException('OCPI source-window pagination changed its bounds.');
            }
        }
    }

    public function pull(OcpiProvider $provider, string $module, array $requestedWindow = []): array
    {
        $this->assertCurrent($provider);
        if (! in_array($module, self::MODULES, true) || $provider->version !== '2.2.1' || ! is_string($provider->endpoints[$module] ?? null)) {
            throw new OcpiException('Requested module has not been negotiated.');
        }
        $window = $this->pullWindow($requestedWindow);
        $url = $provider->endpoints[$module];
        if ($window !== []) {
            parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $existing);
            if (array_key_exists('date_from', $existing) || array_key_exists('date_to', $existing)) {
                throw new OcpiException('Negotiated endpoint already contains source-window bounds.');
            }
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($window, '', '&', PHP_QUERY_RFC3986);
        }
        $records = [];
        $seen = [];
        for ($page = 0; $page < (int) config('ocpi.max_pages', 20); $page++) {
            // Every catalog page may outlive a vendor suspension. Do not
            // dispatch new partner traffic for unpublishable locations/tariffs.
            // Admin-only historical sessions/CDRs remain separately permitted.
            if (in_array($module, ['locations', 'tariffs'], true)
                && ! User::whereKey($provider->owner_id)->where('role', 'vendor')
                    ->where('account_status', 'active')->exists()) {
                throw new OcpiException('Inactive operator owner cannot synchronize public catalog modules.');
            }
            $url = $this->guardedUrl($provider, $url);
            if (isset($seen[$url])) {
                throw new OcpiException('Operator pagination loop detected.');
            }$seen[$url] = true;
            $result = $this->get($provider, $url);
            if (! is_array($result['data']) || ! array_is_list($result['data'])) {
                throw new OcpiException('Module response must contain a record list.');
            }
            $this->verifyWindowRecords($result['data'], $window);
            $records = array_merge($records, $result['data']);
            if (count($records) > (int) config('ocpi.max_records', 1000)) {
                throw new OcpiException('Operator synchronization exceeded the configured record limit.');
            }
            if ($result['next'] === null) {
                return $records;
            }
            $this->verifyWindowNext($result['next'], $window);
            $url = $result['next'];
        }
        throw new OcpiException('Operator pagination exceeded the configured page limit.');
    }

    /** Fixed protocol writes only; the caller durably claims an identity before network I/O. */
    public function write(OcpiProvider $provider, string $module, string $method, array $segments, array $body): array
    {
        if (! in_array([$module, $method], [['tokens_receiver', 'PUT'], ['commands_receiver', 'POST']], true)) {
            throw new OcpiException('Unsupported sandbox protocol write.');
        }
        $this->assertCurrent($provider);
        $c = $this->connection($provider);
        if (! app()->environment(['local', 'testing']) || ! config('ocpi.test_commands_enabled') || ($c['test_commands'] ?? false) !== true || ($c['environment'] ?? null) !== 'sandbox' || ! is_string($provider->endpoints[$module] ?? null)) {
            throw new OcpiException('Approved sandbox command adapter is unavailable.');
        }
        foreach ($segments as $segment) {
            if (! is_string($segment) || ! preg_match('/^[A-Za-z0-9_*.-]{1,64}$/D', $segment) || in_array($segment, ['.', '..'], true)) {
                throw new OcpiException('Invalid protocol target.');
            }
        }
        if (parse_url($provider->endpoints[$module], PHP_URL_QUERY) !== null) {
            throw new OcpiException('Protocol receiver must not contain a query.');
        }
        $url = $this->guardedUrl($provider, rtrim($provider->endpoints[$module], '/').'/'.implode('/', array_map('rawurlencode', $segments)));

        return $this->sendProtocol($provider, $method, $url, $body, $c['authorization_token'], []);
    }

    public function cpoCatalogRequest(OcpiProvider $provider, string $module, string $method, array $payload, int $generation, bool $allowMissing = false, ?string $cdrReceipt = null): array
    {
        $this->assertCurrent($provider);
        $connection = $this->connection($provider);
        $local = app(CredentialExchange::class)->localConfiguration($provider)['roles'][0];
        $endpoint = $provider->endpoints[$module.'_receiver'] ?? null;
        if (! app()->environment(['local', 'testing']) || ! config('ocpi.cpo_push_enabled')
            || ($connection['environment'] ?? null) !== 'sandbox'
            || ! app(CpoCatalogContract::class)->approved($provider)
            || ! in_array([$module, $method], [['locations', 'PUT'], ['tariffs', 'PUT'], ['tariffs', 'DELETE'], ['locations', 'GET'], ['tariffs', 'GET'], ['sessions', 'PUT'], ['sessions', 'GET'], ['cdrs', 'POST'], ['cdrs', 'GET']], true)
            || ! is_string($endpoint) || parse_url($endpoint, PHP_URL_QUERY) !== null
            || $payload['country_code'] !== $local['country_code'] || $payload['party_id'] !== $local['party_id']
            || ! preg_match('/^[STQD][1-9][0-9]{0,18}$/D', $payload['id'])
            || ! in_array($module, app(CpoCatalogContract::class)->modules($provider), true)
            || ($module === 'cdrs' && $method === 'GET' && ! preg_match('/^[A-Za-z0-9_-]{1,36}$/D', $cdrReceipt ?? ''))) {
            throw new OcpiException('Approved sandbox CPO receiver is unavailable.');
        }
        $guardGeneration = function () use ($provider, $generation) {
            $this->assertCurrent($provider);
            if (! DB::table('ocpi_credential_links')->where(['provider_id' => $provider->id, 'generation' => $generation, 'state' => 'active'])->exists()
                || ! DB::table('users')->where(['id' => $provider->owner_id, 'role' => 'vendor', 'account_status' => 'active'])->exists()) {
                throw new OcpiException('Catalog authority changed during delivery.');
            }
        };
        $path = match ([$module, $method]) {
            ['cdrs', 'POST'] => '',
            ['cdrs', 'GET'] => '/'.$cdrReceipt,
            default => '/'.$payload['country_code'].'/'.$payload['party_id'].'/'.$payload['id'],
        };
        $url = $this->guardedUrl($provider, rtrim($endpoint, '/').$path);
        $guardGeneration();
        $result = $this->sendProtocol(
            $provider,
            $method,
            $url,
            in_array($method, ['PUT', 'POST'], true) ? $payload : null,
            $connection['authorization_token'],
            [
                'OCPI-from-country-code' => $local['country_code'],
                'OCPI-from-party-id' => $local['party_id'],
                'OCPI-to-country-code' => $connection['party_country'],
                'OCPI-to-party-id' => $connection['party_id'],
            ],
            allowMissing: $method === 'GET' && $module === 'tariffs' && $allowMissing,
            strictTrace: true,
            maxResponseBytes: $method === 'GET' ? self::CATALOG_READ_RESPONSE_BYTES : self::PROTOCOL_RESPONSE_BYTES,
            requireCdrReceipt: $module === 'cdrs' && $method === 'POST',
        );
        $guardGeneration();

        return $result;
    }

    private function sendProtocol(
        OcpiProvider $provider,
        string $method,
        string $url,
        ?array $body,
        string $token,
        array $headers,
        bool $allowMissing = false,
        bool $strictTrace = false,
        int $maxResponseBytes = self::PROTOCOL_RESPONSE_BYTES,
        bool $requireCdrReceipt = false,
    ): array {
        try {
            $trace = ['X-Request-ID' => (string) Str::uuid(), 'X-Correlation-ID' => (string) Str::uuid()];
            $r = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['stream' => true])
                ->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Token '.$token] + $trace + $headers)
                ->send($method, $url, $body === null ? [] : ['json' => $body]);
            $stream = $r->toPsrResponse()->getBody();
            $text = '';
            try {
                while (! $stream->eof() && strlen($text) <= $maxResponseBytes) {
                    $text .= $stream->read(min(8192, $maxResponseBytes + 1 - strlen($text)));
                }
            } finally {
                $stream->close();
            }
            if ((! $r->successful() && ! ($allowMissing && $r->notFound())) || strlen($text) > $maxResponseBytes) {
                throw new OcpiException('Partner transport outcome requires reconciliation.');
            }
            if ($strictTrace && (! $r->toPsrResponse()->hasHeader('X-Request-ID') || ! $r->toPsrResponse()->hasHeader('X-Correlation-ID'))) {
                throw new OcpiException('Catalog receiver did not confirm request tracing.');
            }
            $this->verifyTrace($r, $trace);
            if ($requireCdrReceipt) {
                $location = $r->toPsrResponse()->getHeader('Location');
                if ($r->status() !== 201 || count($location) !== 1 || strlen($location[0]) > 255
                    || ! preg_match('~^'.preg_quote(rtrim($url, '/'), '~').'/[A-Za-z0-9_-]{1,36}$~D', $this->guardedUrl($provider, $location[0]))) {
                    throw new OcpiException('CDR receiver did not provide a bounded receipt.');
                }
            }
            $response = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            $expected = $r->notFound() ? 2001 : 1000;
            if (! is_array($response) || ($response['status_code'] ?? null) !== $expected || ! $this->validEnvelopeTimestamp($response['timestamp'] ?? null)) {
                throw new OcpiException('Partner protocol outcome requires reconciliation.');
            }
            $this->assertCurrent($provider);
            $this->connection($provider);

            return $response;
        } catch (OcpiException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new OcpiException('Partner outcome unknown; private request and credentials withheld.');
        }
    }
}
