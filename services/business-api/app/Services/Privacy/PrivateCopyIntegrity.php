<?php

namespace App\Services\Privacy;

use JsonException;

/** Verify the exact decrypted bytes and their owner/case binding before disclosure. */
final class PrivateCopyIntegrity
{
    public static function verify(string $body, int $length, string $digest, int $owner, int $case): void
    {
        abort_unless(strlen($body) <= 2 * 1024 * 1024
            && strlen($body) === $length
            && hash_equals($digest, hash('sha256', $body)), 409, 'Private copy integrity failed.');
        try {
            $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(409, 'Private copy integrity failed.');
        }

        abort_unless(is_array($document) && ($document['schema_version'] ?? null) === 1
            && ($document['scope'] ?? null) === 'self_service_account_copy'
            && ($document['owner_id'] ?? null) === $owner
            && ($document['request_id'] ?? null) === $case
            && is_array($document['sections'] ?? null)
            && is_array($document['sections']['profile'] ?? null)
            && ($document['sections']['profile']['id'] ?? null) === $owner,
            409, 'Private copy identity or schema requires review.');

        // Historical copies may contain fewer sections. Every included
        // record section must still be a bounded list of record objects.
        // Reject inconsistent imported nested ownership before disclosure.
        foreach ($document['sections'] as $name => $rows) {
            if ($name === 'profile') {
                continue;
            }
            abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 2500,
                409, 'Private copy section requires review.');
            foreach ($rows as $row) {
                abort_unless(is_array($row) && ! array_is_list($row)
                    && (! array_key_exists('user_id', $row) || $row['user_id'] === $owner),
                    409, 'Private copy record requires review.');
            }
        }
    }
}
