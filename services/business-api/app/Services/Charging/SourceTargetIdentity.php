<?php

namespace App\Services\Charging;

/** The persisted target digest binds an inventory mapping to its exact source tuple. */
final class SourceTargetIdentity
{
    private static function integer(mixed $value, int $min, int $max): bool
    {
        return is_int($value) && $value >= $min && $value <= $max;
    }

    public static function matches(string $protocol, mixed $bindingId, mixed $evse, mixed $connector, mixed $digest): bool
    {
        if (! self::integer($bindingId, 1, PHP_INT_MAX)
            || ! self::integer($connector, 1, 100)
            || ! match ($protocol) {
                '1.6' => $evse === 0,
                '2.0.1' => self::integer($evse, 1, 1000),
                default => false,
            }
            || ! is_string($digest)) {
            return false;
        }

        return hash_equals(hash('sha256', $bindingId.':'.$evse.':'.$connector), $digest);
    }
}
