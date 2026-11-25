<?php

namespace App\Services\Ocpi;

use JsonException;
use stdClass;

/** Preserve the actual JSON collection shape before associative decoding. */
final class OcpiReadDocument
{
    public static function assertShape(string $body, string $kind): void
    {
        try {
            $document = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OcpiException('Malformed operator JSON response.');
        }
        if (! $document instanceof stdClass) {
            throw new OcpiException('Malformed operator response envelope.');
        }
        $data = $document->data ?? null;
        $valid = match ($kind) {
            'versions' => self::objects($data, 20),
            'details' => $data instanceof stdClass && self::objects($data->endpoints ?? null, 30),
            'records' => self::objects($data, null),
            default => false,
        };
        if (! $valid) {
            throw new OcpiException('Malformed operator response collection.');
        }
    }

    private static function objects(mixed $rows, ?int $limit): bool
    {
        if (! is_array($rows) || ($limit !== null && count($rows) > $limit)) {
            return false;
        }
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                return false;
            }
        }

        return true;
    }
}
