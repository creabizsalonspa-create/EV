<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/** A status timestamp must identify an actual source instant, never relative text. */
class SourceStatusTime
{
    public static function parse(mixed $value, int $futureToleranceSeconds = 0): CarbonImmutable
    {
        abort_unless($futureToleranceSeconds >= 0 && $futureToleranceSeconds <= 300, 500, 'Unsupported source clock tolerance.');
        abort_unless(is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value, $parts), 503, 'Invalid source status time.');
        $normalized = $parts[1].'.'.str_pad($parts[2], 6, '0').($parts[3] === 'Z' ? '+00:00' : $parts[3]);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        abort_unless($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)), 503, 'Invalid source status time.');
        $time = CarbonImmutable::instance($parsed)->utc();
        abort_unless($time->lte(now()->addSeconds($futureToleranceSeconds)), 503, 'Source status time is in the future.');

        return $time;
    }
}
