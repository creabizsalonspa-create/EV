<?php

namespace App\Services\Charging;

use DateTimeImmutable;

/** Historical source recovery is evidence of an occurrence, not live capacity. */
final class FaultRecoveryEvidence
{
    public static function timestamp(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || ! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3])(?::?[0-5]\d)?)?$/D', $raw, $parts)) {
            return null;
        }
        // Local database timestamps without an offset are written in UTC.
        $zone = $parts[4] ?? 'Z';
        $zone = $zone === 'Z' ? '+00:00' : $zone;
        if (strlen($zone) === 3) {
            $zone .= ':00';
        } elseif (strlen($zone) === 5) {
            $zone = substr($zone, 0, 3).':'.substr($zone, 3);
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $parts[1].' '.$parts[2].'.'.str_pad($parts[3] ?? '', 6, '0').$zone);
        $errors = DateTimeImmutable::getLastErrors();

        return $time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) ? $time : null;
    }

    public static function cleared(string $protocol, object $row): bool
    {
        return ($row->status ?? null) === 'Available' && match ($protocol) {
            '1.6' => ($row->error_code ?? null) === 'NoError',
            '2.0.1' => in_array($row->error_code ?? null, [null, 'NoError'], true),
            default => false,
        };
    }

    public static function matches(string $protocol, int $bindingId, string $generation, ?object $fault, ?object $recovery, DateTimeImmutable $now): bool
    {
        if ($fault === null || $recovery === null
            || ($fault->binding_id ?? null) !== $bindingId || ($recovery->binding_id ?? null) !== $bindingId
            || ($fault->source_generation ?? null) !== $generation || ($recovery->source_generation ?? null) !== $generation
            || (($fault->status ?? null) !== 'Faulted' && in_array($fault->error_code ?? null, [null, 'NoError'], true))
            || ! is_int($fault->source_event_id ?? null) || $fault->source_event_id < 1
            || ! is_int($recovery->source_event_id ?? null) || $recovery->source_event_id <= $fault->source_event_id
            || ! is_int($fault->evse_number ?? null) || $fault->evse_number < 0
            || ! is_int($fault->connector_number ?? null) || $fault->connector_number < 0
            || ($recovery->evse_number ?? null) !== $fault->evse_number
            || ($recovery->connector_number ?? null) !== $fault->connector_number
            || ! self::cleared($protocol, $recovery)) {
            return false;
        }
        $faultAt = self::timestamp($fault->occurred_at ?? null);
        $recoveryAt = self::timestamp($recovery->occurred_at ?? null);

        return $faultAt !== null && $recoveryAt !== null && $recoveryAt > $faultAt && $recoveryAt <= $now;
    }
}
