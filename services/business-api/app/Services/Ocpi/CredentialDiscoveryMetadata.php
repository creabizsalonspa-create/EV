<?php

namespace App\Services\Ocpi;

use stdClass;
use Throwable;

/** Versions and version details are single, bounded OCPI documents, not pages. */
final class CredentialDiscoveryMetadata
{
    public static function complete(string $body, string $kind, bool $hasLink): bool
    {
        // Never derive credentials write authority from partial advertised
        // metadata. This adapter intentionally rejects any Link header here.
        if ($hasLink) {
            return false;
        }
        try {
            // Preserve actual JSON array versus object shape. Associative
            // decoding can turn an object with numeric keys into a PHP list.
            $reply = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (! $reply instanceof stdClass) {
            return false;
        }
        $data = $reply->data ?? null;
        if ($kind === 'versions') {
            return self::entries($data, 20, ['version', 'url']);
        }

        return $kind === 'details' && $data instanceof stdClass
            && ($data->version ?? null) === '2.2.1'
            && self::entries($data->endpoints ?? null, 30, ['identifier', 'role', 'url']);
    }

    private static function entries(mixed $rows, int $limit, array $keys): bool
    {
        if (! is_array($rows) || count($rows) > $limit) {
            return false;
        }
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                return false;
            }
            foreach ($keys as $key) {
                if (! is_string($row->$key ?? null) || $row->$key === '') {
                    return false;
                }
            }
        }

        return true;
    }
}
