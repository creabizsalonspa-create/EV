<?php

namespace App\Services\Charging;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Historical escalation closure is not proof of current charger clearance. */
final class FaultEscalationReceipt
{
    private static function instant(mixed $raw, DateTimeInterface $clock): ?DateTimeImmutable
    {
        if (! is_string($raw) || ! preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})[ T]([0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?(Z|[+-](?:[01][0-9]|2[0-3])(?::?[0-5][0-9])?)?\z/', $raw, $parts)) {
            return null;
        }
        $zone = $parts[4] ?? 'Z';
        if ($zone === 'Z' || $zone === '') {
            $zone = '+00:00';
        } elseif (strlen($zone) === 3) {
            $zone .= ':00';
        } elseif (strlen($zone) === 5) {
            $zone = substr($zone, 0, 3).':'.substr($zone, 3);
        }
        $value = $parts[1].'T'.$parts[2].'.'.str_pad($parts[3] ?? '', 6, '0').$zone;
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $time->format('Y') === '0000' || $time > $clock) {
            return null;
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    public static function project(object $row, DateTimeInterface $clock): array
    {
        $opened = self::instant($row->created_at ?? null, $clock);
        $closed = self::instant($row->resolved_at ?? null, $clock);
        $reason = $row->resolution_source ?? null;
        $state = 'requires_review';
        if ($opened !== null && $row->resolved_at === null && $reason === null) {
            $state = 'recorded_open';
        } elseif ($opened !== null && $closed !== null && $closed >= $opened
            && in_array($reason, ['operator_acknowledged', 'source_recovered'], true)) {
            $state = 'recorded_closed';
        }

        return [
            'alert_id' => (string) $row->alert_id,
            'state' => $state,
            'opened_at' => $opened?->format('Y-m-d\TH:i:s.u\Z'),
            'closed_at' => $state === 'recorded_closed' ? $closed->format('Y-m-d\TH:i:s.u\Z') : null,
            'resolution_source' => $state === 'recorded_closed' ? $reason : null,
            'physical_verification' => false,
        ];
    }
}
