<?php

namespace App\Services\Discovery;

/**
 * SQLite builds can omit ENABLE_MATH_FUNCTIONS even with PDO SQLite present.
 * Register deterministic PHP equivalents on the active connection, so both
 * first-party and partner nearby searches retain identical bounded SQL.
 */
final class SqliteGeoMath
{
    public static function register(\PDO $pdo): void
    {
        if (! method_exists($pdo, 'sqliteCreateFunction')) {
            throw new \LogicException('SQLite geometric functions are not supported by this PDO connection.');
        }

        // OCPI normalizes valid coordinates as strings. SQLite CAST alone
        // silently turns malformed text into zero, so only finite numeric
        // values may participate in viewport matching.
        if (! $pdo->sqliteCreateFunction('discovery_coordinate_number', static function (mixed $value): ?float {
            if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) {
                return null;
            }
            if (is_string($value) && preg_match('/^[+-]?([0-9]{1,40}([.][0-9]{0,40})?|[.][0-9]{1,40})([eE][+-]?[0-9]{1,2})?$/D', trim($value, ' ')) !== 1) {
                return null;
            }
            $number = (float) $value;

            return is_finite($number) ? $number : null;
        }, 1, \PDO::SQLITE_DETERMINISTIC)) {
            throw new \LogicException('Unable to register SQLite discovery coordinate function.');
        }

        foreach (['sin', 'cos', 'asin', 'sqrt'] as $function) {
            if (! $pdo->sqliteCreateFunction($function, $function, 1, \PDO::SQLITE_DETERMINISTIC)) {
                throw new \LogicException('Unable to register SQLite discovery geometric function.');
            }
        }
    }
}
