<?php

namespace App\Services\Charging;

/**
 * The Hasura admin secret is a privileged source credential, never a
 * caller-supplied URL authorization. Do not send it in plaintext to an
 * arbitrary HTTP host, URL userinfo, or an unreviewed redirect target.
 *
 * This is a transport boundary, not permission to operate a real charger.
 */
final class CsmsGraphqlTransport
{
    private const LOCAL_HTTP_HOSTS = [
        'localhost', '127.0.0.1', '[::1]',
        'citrine', 'hasura', 'citrine-hasura', 'ev-hasura',
        'charging-graphql',
    ];

    public function approved(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) < 12 || strlen($url) > 512
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! is_string($parts['path'] ?? null) || strlen($parts['path']) < 2
            || ! str_starts_with($parts['path'], '/')) {
            return false;
        }

        if ($parts['scheme'] === 'http') {
            // Never leak the Hasura admin secret to arbitrary plain HTTP,
            // even in development. Internal simulator hostnames are exact,
            // and are never accepted under production.
            return app()->environment(['local', 'testing'])
                && in_array(strtolower($parts['host']), self::LOCAL_HTTP_HOSTS, true)
                && $parts['path'] === '/v1/graphql';
        }

        // HTTPS still needs an operator-approved configuration; this only
        // verifies that the transport is encrypted and has no embedded
        // credential/query/fragment. Source identity is verified separately.
        return true;
    }

    /** Validate and return the identical URL that must be used for HTTP. */
    public function requireApproved(): string
    {
        $url = config('csms.graphql_url');
        abort_unless($this->approved($url), 503,
            'Authenticated CSMS source transport is not approved.');

        return $url;
    }
}
