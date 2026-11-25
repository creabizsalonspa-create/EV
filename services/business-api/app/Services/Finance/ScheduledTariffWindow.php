<?php

namespace App\Services\Finance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Strict UTC scheduling for explicitly simulated tariff versions. */
final class ScheduledTariffWindow
{
    public static function parse(string $value): ?DateTimeImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value && $date->format('Y') !== '0000'
            ? $date : null;
    }

    public static function allowed(DateTimeImmutable $effective, DateTimeInterface $now): bool
    {
        // Compare actual instants, including the current clock's fractional second.
        $clock = DateTimeImmutable::createFromInterface($now);

        return $effective >= $clock->modify('+60 seconds') && $effective <= $clock->modify('+30 days');
    }
}
