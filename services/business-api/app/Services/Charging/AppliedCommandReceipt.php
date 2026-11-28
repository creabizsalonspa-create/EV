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

    /** Reconstruct the exact canonical identity retained by SessionBridge::ingest. */
    public static function fingerprintMatches(object $event, object $link, ?array $sourceTarget): bool
    {
        $sequence = self::unsigned($event->sequence ?? null);
        $meter = self::unsigned($event->meter_wh ?? null);
        $sessionId = self::unsigned($link->charging_session_id ?? null);
        $observed = self::instant($event->occurred_at ?? null);
        $fingerprint = $event->fingerprint ?? null;
        if ($sequence === null || $meter === null || $sessionId === null || $observed === null
            || ! is_string($fingerprint) || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)
            || ! is_string($link->identifier ?? null) || ! is_string($link->protocol ?? null)
            || ! is_string($link->transaction_id ?? null) || ! is_string($event->type ?? null)) {
            return false;
        }
        $identity = [$sessionId, $link->identifier, $link->protocol, $link->transaction_id,
            $event->type, $sequence, $meter, $observed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')];
        if ($sourceTarget !== null) {
            if (count($sourceTarget) !== 2 || ! array_is_list($sourceTarget)
                || self::unsigned($sourceTarget[0]) === null || self::unsigned($sourceTarget[1]) === null) {
                return false;
            }
            $identity[] = (int) $sourceTarget[0];
            $identity[] = (int) $sourceTarget[1];
        }

        return hash_equals($fingerprint, hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)));
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
        if (($event->outcome ?? null) !== 'applied' || ($event->type ?? null) !== ($end ? 'ended' : 'started')
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
