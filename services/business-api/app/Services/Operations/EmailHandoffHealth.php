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
            $now = now('UTC');
            $cutoff = $now->copy()->subMinutes(15);
            $outbox = DB::table('notification_email_outbox');

            return [
                'state' => 'available',
                'outcome_unknown' => (clone $outbox)->where('state', 'outcome_unknown')->count(),
                'stale_claimed' => (clone $outbox)->where('state', 'claimed')
                    ->where(fn ($query) => $query
                        ->where('claimed_at', '<=', $cutoff)
                        ->orWhere(fn ($missing) => $missing->whereNull('claimed_at')->where('created_at', '<=', $cutoff)))
                    ->count(),
                'age_threshold_seconds' => 900,
                'delivery_confirmed' => false,
                'automatic_retry' => false,
            ];
        } catch (Throwable) {
            // Missing or inaccessible storage is unknown, never an empty outbox.
            return [
                'state' => 'unavailable',
                'outcome_unknown' => null,
                'stale_claimed' => null,
                'age_threshold_seconds' => 900,
                'delivery_confirmed' => false,
                'automatic_retry' => false,
            ];
        }
    }
}
