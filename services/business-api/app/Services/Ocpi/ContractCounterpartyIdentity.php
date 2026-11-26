<?php

namespace App\Services\Ocpi;

/** Non-secret identity of the approved counterparty, independent of credential rotation. */
final class ContractCounterpartyIdentity
{
    public static function fingerprint(string $key, mixed $connection): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $key) || ! is_array($connection)) {
            return null;
        }
        $origin = $connection['origin'] ?? null;
        $country = $connection['party_country'] ?? null;
        $party = $connection['party_id'] ?? null;
        $role = $connection['party_role'] ?? null;
        $environment = $connection['environment'] ?? 'unverified';
        $direction = $connection['registration_direction'] ?? 'outbound';
        $versions = $connection['versions_path'] ?? '/ocpi/versions';
        if (! is_string($origin) || preg_match('/[\x00-\x20\x7f]/', $origin)
            || ! is_string($country) || ! preg_match('/^[A-Z]{2}$/D', $country)
            || ! is_string($party) || ! preg_match('/^[A-Z0-9]{3}$/D', $party)
            || ! in_array($role, ['CPO', 'EMSP'], true)
            || ! is_string($environment) || ! preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $environment)
            || ! in_array($direction, ['outbound', 'incoming'], true)
            || ! is_string($versions) || ! preg_match('~^/(?!/)[A-Za-z0-9._\~!$&\x27()+,;=:@%/-]+$~D', $versions)) {
            return null;
        }
        $url = parse_url($origin);
        $host = strtolower($url['host'] ?? '');
        if (! $url || ($url['scheme'] ?? null) !== 'https'
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid)$/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP)
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ($url['port'] ?? 443) !== 443 || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return hash('sha256', json_encode([
            'ocpi-contract-counterparty-v1', $key, 'https://'.$host, $country, $party,
            $role, $environment, $direction, $versions,
        ], JSON_THROW_ON_ERROR));
    }

    public static function current(string $key): ?string
    {
        return self::fingerprint($key, config('ocpi.connections.'.$key));
    }

    /** The digest includes its alias, so another alias cannot satisfy an old review. */
    public static function configuredFingerprints(): array
    {
        $hashes = [];
        foreach ((array) config('ocpi.connections', []) as $key => $connection) {
            if (is_string($key) && ($hash = self::fingerprint($key, $connection)) !== null) {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }
}
