<?php

namespace App\Services\Charging;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/** Validate retained applied evidence against the durable session receipt, not transport acceptance. */
final class AppliedCommandReceipt
{
    private static function unsigned(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
            || (string) (int) $value !== $value) {
            return null;
        }

        return (int) $value;
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3])(?::?[0-5]\d)?)?\z/', $value)) {
            return null;
        }
        try {
            $time = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $time->format('Y-m-d H:i:s') === str_replace('T', ' ', substr($value, 0, 19)) ? $time : null;
    }

    public static function matches(object $event, object $link, object $session, bool $end): bool
    {
        $sequence = self::unsigned($event->sequence ?? null);
        $meter = self::unsigned($event->meter_wh ?? null);
        $last = self::unsigned($link->last_sequence ?? null);
        $startMeter = self::unsigned($link->meter_start_wh ?? null);
        $lastMeter = self::unsigned($link->last_meter_wh ?? null);
        $observed = self::instant($event->occurred_at ?? null);
        $started = self::instant($link->started_at ?? null);
        // Historical database timestamp precision may differ from the signed
        // input, so do not reconstruct a hash from lossy retained timestamps.
        // At minimum, a missing or malformed canonical digest is not evidence.
        $fingerprint = $event->fingerprint ?? null;
        if (! is_string($fingerprint) || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)
            || ($event->outcome ?? null) !== 'applied' || ($event->type ?? null) !== ($end ? 'ended' : 'started')
            || $sequence === null || $meter === null || $last === null || $startMeter === null
            || $lastMeter === null || $lastMeter < $startMeter || $observed === null || $started === null) {
            return false;
        }
        if (! $end) {
            return in_array($session->status ?? null, ['charging', 'stopping', 'stopped'], true)
                && $sequence === 0 && $meter === $startMeter && $observed == $started;
        }
        $ended = self::instant($link->ended_at ?? null);
        $stopped = self::instant($session->stopped_at ?? null);

        return ($session->status ?? null) === 'stopped' && $sequence > 0 && $sequence === $last
            && $meter === $lastMeter && $ended !== null && $stopped !== null
            && $observed == $ended && $ended == $stopped && $ended >= $started
            && self::unsigned($session->energy_wh ?? null) === $lastMeter - $startMeter;
    }
}
