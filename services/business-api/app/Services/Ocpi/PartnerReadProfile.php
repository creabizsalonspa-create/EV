<?php

namespace App\Services\Ocpi;

/** Server-provisioned per-network read scope; never grants write or roaming authority. */
final class PartnerReadProfile
{
    public const MODULES = ['locations', 'tariffs', 'sessions', 'cdrs'];

    public static function modules(array $connection): array
    {
        $modules = array_key_exists('read_modules', $connection)
            ? $connection['read_modules'] : self::MODULES;
        if (! is_array($modules) || ! array_is_list($modules) || count($modules) > count(self::MODULES)) {
            throw new OcpiException('Invalid approved network read scope.');
        }
        foreach ($modules as $module) {
            if (! is_string($module) || ! in_array($module, self::MODULES, true)) {
                throw new OcpiException('Invalid approved network read scope.');
            }
        }
        if (count(array_unique($modules)) !== count($modules)) {
            throw new OcpiException('Invalid approved network read scope.');
        }

        // Canonical ordering prevents equivalent allowlists creating different
        // module projections. An explicit empty list admits no pull modules.
        return array_values(array_intersect(self::MODULES, $modules));
    }

    public static function allows(mixed $connection, string $module): bool
    {
        if (! is_array($connection)) {
            return false;
        }
        try {
            return in_array($module, self::modules($connection), true);
        } catch (OcpiException) {
            return false;
        }
    }
}
