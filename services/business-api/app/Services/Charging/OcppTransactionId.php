<?php

namespace App\Services\Charging;

/** OCPP 1.6 requires a canonical signed 32-bit transaction ID. */
final class OcppTransactionId
{
    public static function valid16(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) !== 1) {
            return false;
        }

        return strlen($value) < 10 || strcmp($value, '2147483647') <= 0;
    }
}
