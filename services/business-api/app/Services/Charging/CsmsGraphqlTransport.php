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
        $parts = CsmsEndpoint::parse($url, 512);
        if ($parts === null || strlen($parts['path'] ?? '') < 2) {
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
