<?php

namespace App\Services\Notifications;

use DateTimeImmutable;
use Throwable;

/** Private, allowlisted history projection. Never interprets transport handoff as delivery. */
final class EmailNoticeHistory
{
    private const EVIDENCE = ['SIMULATED_EMAIL_HANDOFF', 'SMTP_HANDOFF_UNVERIFIED'];

    private const FAILURES = [
        'cancelled' => ['user_opted_out', 'consent_or_visibility_changed'],
        'outcome_unknown' => ['transport_outcome_unknown', 'interrupted_handoff_unknown'],
    ];

    private static function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})[ T]([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.[0-9]{1,6})?(?:Z|[+-](?:0[0-9]|1[0-4])(?::?[0-5][0-9])?)?\z/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }
        try {
            new DateTimeImmutable($value);

            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    public static function project(object $row): array
    {
        $id = $row->id ?? null;
        $id = is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id) ? $id : null;
        $state = $row->state ?? null;
        $evidence = $row->evidence ?? null;
        $failure = $row->failure_code ?? null;
        $created = self::timestamp($row->created_at ?? null);
        $claimed = self::timestamp($row->claimed_at ?? null);
        $completed = self::timestamp($row->completed_at ?? null);
        $known = $id !== null && $created !== null && in_array($evidence, self::EVIDENCE, true)
            && in_array($state, ['queued', 'claimed', 'handed_off', 'outcome_unknown', 'cancelled'], true)
            && (($row->claimed_at ?? null) === null || $claimed !== null)
            && (($row->completed_at ?? null) === null || $completed !== null);
        if ($known) {
            $known = match ($state) {
                'queued' => $claimed === null && $completed === null && $failure === null,
                'claimed' => $completed === null && $failure === null,
                'handed_off' => $claimed !== null && $completed !== null && $failure === null,
                'cancelled', 'outcome_unknown' => $completed !== null && in_array($failure, self::FAILURES[$state], true),
                default => false,
            };
        }

        // Retain the row/page count even when its stored evidence is corrupt.
        // Never echo arbitrary codes, raw exception text, or forged success.
        return [
            'id' => $id,
            'state' => $known ? $state : 'review_required',
            'failure_code' => $known ? $failure : null,
            'evidence' => $known ? $evidence : null,
            'created_at' => $created ?? '',
            'claimed_at' => $known ? $claimed : null,
            'completed_at' => $known ? $completed : null,
        ];
    }
}
