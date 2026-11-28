<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\Http;
use Throwable;

/** Short-lived, memory-only service authority. Never retries or dispatches commands. */
class OperationsCredentials
{
    /** Snapshot the entire trust policy before requesting a short-lived credential. */
    private function authority(): array
    {
        return [
            'environment' => app()->environment(),
            'mode' => config('csms.operations_auth_mode', 'development'),
            'endpoint' => config('csms.operations_oauth_token_url'),
            'client' => config('csms.operations_oauth_client_id'),
            'secret' => config('csms.operations_oauth_client_secret'),
            'scope' => config('csms.operations_oauth_scope'),
            'issuer' => config('csms.operations_oauth_issuer'),
            'audience' => config('csms.operations_oauth_audience'),
            'keys' => config('csms.operations_oauth_public_keys', []),
        ];
    }

    private function requireAuthority(array $authority): void
    {
        abort_unless($this->authority() === $authority, 503);
    }

    public function token(string $operationsBase): string
    {
        try {
            $authority = $this->authority();
            if ($authority['mode'] === 'development') {
                abort_unless(app()->environment(['local', 'testing']), 503);
                $this->url($operationsBase, false);
                $r = Http::connectTimeout(3)->timeout(5)->withoutRedirecting()->post(rtrim($operationsBase, '/').'/dev/token', ['roles' => ['admin'], 'tenantId' => 1]);
                $this->requireAuthority($authority);
                $token = $r->json('accessToken');
                abort_unless($r->successful() && is_string($token) && $token !== '' && strlen($token) <= 16384 && ! preg_match('/[\x00-\x20\x7f]/', $token), 503);

                return $token;
            }
            abort_unless($authority['mode'] === 'oauth2', 503);
            $this->url($operationsBase, true);
            abort_unless($operationsBase === $authority['audience'], 503);
            $endpoint = (string) $authority['endpoint'];
            $this->url($endpoint, true);
            $client = $authority['client'];
            $secret = $authority['secret'];
            $scope = $authority['scope'];
            abort_unless(is_string($client) && preg_match('/^[A-Za-z0-9._-]{1,200}$/D', $client) && is_string($secret) && strlen($secret) >= 32 && strlen($secret) <= 4096 && ! preg_match('/[\x00-\x20\x7f]/', $secret) && is_string($scope) && preg_match('/^[A-Za-z0-9:._-]{1,100}$/D', $scope), 503);
            $r = Http::connectTimeout(3)->timeout(5)->withoutRedirecting()->asForm()->withBasicAuth($client, $secret)->post($endpoint, ['grant_type' => 'client_credentials', 'scope' => $scope]);
            $this->requireAuthority($authority);
            abort_unless($r->successful() && strlen($r->body()) <= 32768 && is_string($r->json('token_type')) && strcasecmp($r->json('token_type'), 'Bearer') === 0, 503);
            $token = $r->json('access_token');
            abort_unless(is_string($token), 503);
            $this->verify($token, $operationsBase, $scope, $authority);
            $this->requireAuthority($authority);

            return $token;
        } catch (Throwable) {
            // Provider bodies, credentials and JWTs never escape through errors.
            abort(503, 'CSMS service credentials are unavailable; inspect configured identity and provider health.');
        }
    }

    /** Keep the issued token bound to its captured trust policy until wire dispatch. */
    public function tokenForDispatch(string $operationsBase): \Closure
    {
        $authority = $this->authority();
        $token = $this->token($operationsBase);
        $this->requireAuthority($authority);

        return function () use ($authority, $token, $operationsBase): string {
            $this->requireAuthority($authority);
            if ($authority['mode'] === 'oauth2') {
                // Local checks and source reads may have consumed the token's
                // usable lifetime or changed the pinned keyring since issuance.
                $this->verify($token, $operationsBase, $authority['scope'], $authority);
            }
            $this->requireAuthority($authority);

            return $token;
        };
    }

    private function url(string $url, bool $tls): void
    {
        $parts = parse_url($url);
        abort_unless(is_array($parts) && isset($parts['host']) && ! isset($parts['query']) && ! isset($parts['fragment']) && strlen($url) <= 2048 && ! preg_match('/[\x00-\x20\x7f\\\\]/', $url) && in_array($parts['scheme'] ?? null, $tls ? ['https'] : ['http', 'https'], true), 503);
        // Reject ambiguous host encodings and all embedded credentials.
        abort_if(isset($parts['user']) || isset($parts['pass']), 503);
        abort_unless(preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])$/D', $parts['host'])
            && (! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535)), 503);
        if ($parts['scheme'] === 'http') {
            abort_unless(in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]', 'citrine'], true), 503);
        }
    }

    private function decode(string $part): string
    {
        abort_unless($part !== '' && preg_match('/^[A-Za-z0-9_-]+$/D', $part), 503);
        $raw = base64_decode(strtr($part, '-_', '+/'), true);
        abort_unless(is_string($raw) && rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $part, 503);

        return $raw;
    }

    /** Reject ambiguous JSON members before trusting a signed JWT's claims. */
    private function jwtObject(string $json): array
    {
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        abort_unless(is_array($value) && str_starts_with(ltrim($json), '{'), 503);
        // JSON syntax and depth are already validated. Walk only object braces
        // and quoted strings, skipping escaped characters, to detect repeated
        // decoded member names (including escaped aliases) in every object.
        $objects = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            if ($json[$i] === '{') {
                $objects[] = [];

                continue;
            }
            if ($json[$i] === '}') {
                array_pop($objects);

                continue;
            }
            if ($json[$i] !== '"') {
                continue;
            }
            $start = $i++;
            while ($i < $length && $json[$i] !== '"') {
                $i += $json[$i] === '\\' ? 2 : 1;
            }
            $next = $i + 1 + strspn($json, " \t\r\n", $i + 1);
            if ($next < $length && $json[$next] === ':') {
                $name = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                $key = "\0".$name;
                $index = count($objects) - 1;
                abort_if(isset($objects[$index][$key]), 503);
                $objects[$index][$key] = true;
            }
        }

        return $value;
    }

    private function verify(string $token, string $audience, string $scope, array $authority): void
    {
        abort_unless(strlen($token) <= 16384 && count($pieces = explode('.', $token)) === 3, 503);
        $header = $this->jwtObject($this->decode($pieces[0]));
        $claims = $this->jwtObject($this->decode($pieces[1]));
        abort_unless(is_array($header) && ($header['alg'] ?? null) === 'RS256' && ($header['typ'] ?? null) === 'JWT' && is_string($header['kid'] ?? null) && ! array_key_exists('crit', $header) && ! array_key_exists('jku', $header) && ! array_key_exists('jwk', $header) && ! array_key_exists('x5u', $header) && is_array($claims), 503);
        $keys = $authority['keys'];
        $pem = is_array($keys) ? ($keys[$header['kid']] ?? null) : null;
        abort_unless(is_string($pem) && strlen($pem) <= 16384, 503);
        $key = openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless($details && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 && openssl_verify($pieces[0].'.'.$pieces[1], $this->decode($pieces[2]), $key, OPENSSL_ALGO_SHA256) === 1, 503);
        $now = now()->getTimestamp();
        $issuer = $authority['issuer'];
        abort_unless(is_string($issuer) && $issuer !== '' && ($claims['iss'] ?? null) === $issuer && ($claims['aud'] ?? null) === $audience && ($claims['sub'] ?? null) === $authority['client'] && ($claims['tenantId'] ?? null) === 1 && is_int($claims['iat'] ?? null) && is_int($claims['exp'] ?? null) && $claims['iat'] <= $now && $claims['iat'] >= $now - 900 && $claims['exp'] > $now + 10 && $claims['exp'] <= $claims['iat'] + 900 && (! array_key_exists('nbf', $claims) || (is_int($claims['nbf']) && $claims['nbf'] <= $now)) && is_array($claims['roles'] ?? null) && array_is_list($claims['roles']) && count($claims['roles']) <= 32 && in_array('admin', $claims['roles'], true) && is_string($claims['scope'] ?? null) && in_array($scope, explode(' ', $claims['scope']), true), 503);
    }
}
