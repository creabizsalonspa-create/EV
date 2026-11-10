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

        foreach (['sin', 'cos', 'asin', 'sqrt'] as $function) {
            if (! $pdo->sqliteCreateFunction($function, $function, 1, \PDO::SQLITE_DETERMINISTIC)) {
                throw new \LogicException('Unable to register SQLite discovery geometric function.');
            }
        }
    }
}
