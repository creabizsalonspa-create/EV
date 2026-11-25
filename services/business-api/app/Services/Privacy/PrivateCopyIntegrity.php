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
            && is_array($document['sections'] ?? null),
            409, 'Private copy identity or schema requires review.');
    }
}
