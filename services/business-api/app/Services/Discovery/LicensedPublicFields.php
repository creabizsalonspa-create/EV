<?php

namespace App\Services\Discovery;

use DateTimeImmutable;
use DateTimeZone;

/** Revalidate the scalar public field contract at the untrusted cache boundary. */
final class LicensedPublicFields
{
    public static function text(mixed $value, int $limit, bool $allowEmpty = true): bool
    {
        return is_string($value)
            && ($allowEmpty || trim($value) !== '')
            && preg_match('/^[^\x00-\x1f\x7f]{0,'.$limit.'}$/uD', $value) === 1;
    }

    public static function sourceClock(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)Z$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $date !== false && DateTimeImmutable::getLastErrors() === false
            && $date->format('Y-m-d\TH:i:s\Z') === $value ? $date : null;
    }
}
