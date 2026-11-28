<?php

namespace App\Services\Charging;

/** A verified historical source row is never silently replaced or recreated. */
final class RiderSourceIdentity
{
    public static function matches(?int $recorded, ?array $observed, bool $retiring = false): bool
    {
        if ($recorded !== null && $recorded <= 0) {
            return false;
        }
        if ($observed === null) {
            return $retiring || $recorded === null;
        }
        $id = $observed['id'] ?? null;

        return is_int($id) && $id > 0 && ($recorded === null || $id === $recorded);
    }
}
