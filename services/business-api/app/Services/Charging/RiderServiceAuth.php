<?php

namespace App\Services\Charging;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/** OAuth client-credentials access tokens are opaque authenticated ciphertext. */
class RiderServiceAuth
{
    public const AUDIENCE = 'creabiz-rider-authorize';

    public function transport(Request $r): void
    {
        abort_unless(config('csms.rider_enabled') && config('csms.session_bridge_enabled') && ! app()->environment('production'), 503);
        abort_unless($r->isSecure() || (config('csms.ingress_allow_local_http') && app()->environment(['local', 'testing']) && IpUtils::checkIp($r->ip(), ['127.0.0.0/8', '172.16.0.0/12', '10.0.0.0/8', '192.168.0.0/16', '::1'])), 503);
        abort_if(strlen($r->getContent()) > 4096, 413);
    }

    public function issue(Request $r): string
    {
        $this->transport($r);
        $v = $r->validate(['grant_type' => 'required|in:client_credentials', 'client_id' => 'required|string|max:80', 'client_secret' => 'required|string|max:256', 'audience' => 'required|in:'.self::AUDIENCE]);
        [$client, $secret, $authority] = $this->credentials();
        abort_unless(hash_equals($client, $v['client_id']) && hash_equals($secret, $v['client_secret']), 401);
        $issued = time();

        return Crypt::encryptString(json_encode(['version' => 2, 'audience' => self::AUDIENCE, 'tenant_id' => 1, 'issued_at' => $issued, 'expires_at' => $issued + 120, 'nonce' => (string) Str::uuid(), 'authority' => $authority], JSON_THROW_ON_ERROR));
    }

    /** Bind opaque callback tokens to the current service credential, not only APP_KEY. */
    private function credentials(): array
    {
        $client = (string) config('csms.rider_client_id');
        $secret = (string) config('csms.rider_client_secret');
        abort_unless(strlen($client) >= 1 && strlen($client) <= 80 && strlen($secret) >= 32 && strlen($secret) <= 256, 503);
        $authority = hash_hmac('sha256', "creabiz-rider-callback-v2\n".$client, $secret);

        return [$client, $secret, $authority];
    }

    public function verify(Request $r): void
    {
        $this->transport($r);
        [, , $authority] = $this->credentials();
        $token = $r->bearerToken();
        abort_unless(is_string($token) && strlen($token) <= 2048, 401);
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(401);
        }
        abort_unless(is_array($claims) && ($claims['version'] ?? null) === 2 && is_string($claims['authority'] ?? null) && hash_equals($authority, $claims['authority']) && ($claims['audience'] ?? null) === self::AUDIENCE && ($claims['tenant_id'] ?? null) === 1 && is_int($claims['issued_at'] ?? null) && is_int($claims['expires_at'] ?? null) && $claims['issued_at'] <= time() && $claims['issued_at'] >= time() - 120 && $claims['expires_at'] > time() && $claims['expires_at'] <= $claims['issued_at'] + 120, 401);
    }
}
