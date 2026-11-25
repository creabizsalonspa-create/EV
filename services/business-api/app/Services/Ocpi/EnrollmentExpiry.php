<?php

namespace App\Services\Ocpi;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** An incoming bootstrap grants only the strictly bounded prepared UTC window. */
final class EnrollmentExpiry
{
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T]([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.(\d{1,6}))?Z?$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        $canonical = $parts[1].'-'.$parts[2].'-'.$parts[3].' '.$parts[4].':'.$parts[5].':'.$parts[6].'.'.str_pad($parts[7] ?? '', 6, '0');
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $canonical, new DateTimeZone('UTC'));

        return $instant !== false && DateTimeImmutable::getLastErrors() === false ? $instant : null;
    }

    public static function future(mixed $value, DateTimeInterface $checkedAt): bool
    {
        $instant = self::parse($value);

        return $instant !== null && $instant > $checkedAt;
    }

    public static function same(mixed $left, mixed $right): bool
    {
        $first = self::parse($left);
        $second = self::parse($right);

        return $first !== null && $second !== null && $first == $second;
    }
}
