<?php

namespace App\Services\Discovery;

use JsonException;

/** Validate the original upstream document before associative projection. */
final class LicensedSourceDocument
{
    public static function records(string $body): array
    {
        // PHP's associative JSON decoder turns {} and {"0": ...}
        // into lists. Such responses are not licensed station samples.
        $trimmed = ltrim($body, " \t\r\n");
        if ($trimmed === '' || $trimmed[0] !== '[') {
            throw new LookupFailure('invalid_response');
        }
        try {
            $records = json_decode($body, true, 48, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LookupFailure('invalid_response');
        }
        if (! is_array($records) || ! array_is_list($records) || count($records) > 25) {
            throw new LookupFailure('invalid_response');
        }

        return $records;
    }
}
