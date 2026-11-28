<?php

namespace App\Services\Charging;

/** Validate the complete configured keyring and typed grants before use. */
final class OperationsTrustPolicy
{
    public static function keys(mixed $keys): bool
    {
        if (! is_array($keys) || count($keys) < 1 || count($keys) > 32) {
            return false;
        }
        foreach ($keys as $kid => $pem) {
            if (! self::keyId($kid) || ! is_string($pem) || strlen($pem) > 16384
                || ! preg_match('/\A-----BEGIN PUBLIC KEY-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END PUBLIC KEY-----\r?\n?\z/D', $pem)) {
                return false;
            }
            $key = openssl_pkey_get_public($pem);
            $details = $key ? openssl_pkey_get_details($key) : false;
            if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                return false;
            }
        }

        return true;
    }

    public static function keyId(mixed $kid): bool
    {
        return is_string($kid) && preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kid) === 1;
    }

    public static function grants(mixed $roles, mixed $scope, string $required): bool
    {
        if (! is_array($roles) || ! array_is_list($roles) || count($roles) > 32
            || ! in_array('admin', $roles, true) || ! is_string($scope) || strlen($scope) > 4096) {
            return false;
        }
        foreach ($roles as $role) {
            if (! is_string($role) || ! preg_match('/^[A-Za-z0-9:._-]{1,100}$/D', $role)) {
                return false;
            }
        }
        $scopes = explode(' ', $scope);
        foreach ($scopes as $value) {
            // OAuth scope-token: printable ASCII except double quote and backslash.
            if (! preg_match('/^[\x21\x23-\x5b\x5d-\x7e]+$/D', $value)) {
                return false;
            }
        }

        return count(array_unique($roles)) === count($roles)
            && count(array_unique($scopes)) === count($scopes)
            && in_array($required, $scopes, true);
    }
}
