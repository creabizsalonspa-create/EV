<?php

namespace App\Services\Charging;

use JsonException;
use stdClass;

/** Interpret only the exact signed, flat JSON event body. */
final class SignedSessionEventPayload
{
    public static function decode(string $raw): array
    {
        abort_if(strlen($raw) > 16384, 413);
        try {
            $object = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, 'A valid signed JSON event object is required.');
        }
        abort_unless($object instanceof stdClass, 422, 'A signed JSON event object is required.');
        $fields = get_object_vars($object);
        $integerFields = ['session_id', 'sequence', 'meter_wh', 'source_evse_number', 'source_connector_number'];
        $stringFields = ['event_id', 'identifier', 'protocol', 'transaction_id', 'type', 'occurred_at'];
        foreach ($fields as $name => $value) {
            abort_unless((in_array($name, $integerFields, true) && is_int($value))
                || (in_array($name, $stringFields, true) && is_string($value)), 422,
                'Signed event fields require exact JSON types.');
        }

        // Syntax was validated above and all values are scalar. Each colon
        // outside a quoted string therefore marks one top-level member.
        // The decoded object collapses duplicate (including escaped) keys.
        $members = 0;
        $quoted = false;
        for ($i = 0, $length = strlen($raw); $i < $length; $i++) {
            $char = $raw[$i];
            if ($quoted && $char === '\\') {
                $i++;

                continue;
            }
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === ':') {
                $members++;
            }
        }
        abort_unless($members === count($fields), 422, 'Duplicate signed event fields are not permitted.');

        return $fields;
    }
}
