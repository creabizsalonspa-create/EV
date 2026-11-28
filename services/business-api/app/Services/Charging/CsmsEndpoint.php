<?php

namespace App\Services\Charging;

/** A single unambiguous configured HTTP(S) authority, not a source authorization. */
final class CsmsEndpoint
{
    public static function parse(mixed $url, int $maximum = 2048): ?array
    {
        if (! is_string($url) || strlen($url) > $maximum || preg_match('/[^\x21-\x7e]|\\\\/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null) || ! self::host($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && (! preg_match('~^/[A-Za-z0-9._\~/\-]*$~D', $path)
            || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $path))) {
            return null;
        }
        // Do not allow the HTTP library to reinterpret a different authority
        // or path than the one inspected, including empty or padded ports.
        $canonical = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path;

        return $canonical === $url ? $parts : null;
    }

    private static function host(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        // Reject numeric and hexadecimal alternative IPv4 spellings, empty
        // labels, trailing dots and non-ASCII authority encodings. Configured
        // international DNS names must use their explicit ASCII A-labels.
        if (strlen($host) > 253 || preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)$/iD', $host)) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (! preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $label)) {
                return false;
            }
        }

        return true;
    }
}
