<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only review signals for opt-in email handoff receipts.
 *
 * A claimed SMTP send can have an unknown outcome even when the worker crashed;
 * observing it must never cause a resend. Counts do not expose recipients,
 * content, transport configuration, exception text, or receipt identifiers.
 */
final class EmailHandoffHealth
{
    public function snapshot(): array
    {
        try {
            // A failed read on PostgreSQL must roll back its own savepoint,
            // not poison the caller's Admin/incident transaction.
            return DB::transaction(function () {
                $now = now('UTC');
                $cutoff = $now->copy()->subMinutes(15);
                // A single statement observes the claim and terminal states together.
                // A concurrent recovery can move a receipt between these states.
                $counts = DB::table('notification_email_outbox')
                    ->whereIn('state', ['outcome_unknown', 'queued', 'claimed'])
                    ->selectRaw(
                        "COUNT(CASE WHEN state = 'outcome_unknown' THEN 1 END) AS outcome_unknown,
                         COUNT(CASE WHEN state = 'queued' AND created_at <= ? THEN 1 END) AS overdue_queued,
                         COUNT(CASE WHEN state = 'claimed' AND COALESCE(claimed_at, created_at) <= ? THEN 1 END) AS stale_claimed",
                        [$cutoff, $cutoff],
                    )
                    ->first();

                return [
                    'state' => 'available',
                    'outcome_unknown' => (int) $counts->outcome_unknown,
                    'overdue_queued' => (int) $counts->overdue_queued,
                    'stale_claimed' => (int) $counts->stale_claimed,
                    'age_threshold_seconds' => 900,
                    'delivery_confirmed' => false,
                    'automatic_retry' => false,
                ];
            });
        } catch (Throwable) {
            // Missing or inaccessible storage is unknown, never an empty outbox.
            return [
                'state' => 'unavailable',
                'outcome_unknown' => null,
                'stale_claimed' => null,
                'overdue_queued' => null,
                'age_threshold_seconds' => 900,
                'delivery_confirmed' => false,
                'automatic_retry' => false,
            ];
        }
    }
}
