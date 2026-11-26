<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Durable, local-only incident transitions from read-only process telemetry.
 *
 * Unknown observations never open, resolve or falsely acknowledge an incident.
 * The row lock serializes concurrent scanners, including PostgreSQL workers.
 * This service never dispatches, retries, drains, modifies or acknowledges jobs,
 * charging commands, payment instructions or external provider messages.
 */
final class OperationalIncidents
{
    private const KEYS = ['queue_worker_stale', 'scheduler_stale', 'queue_backlog', 'queue_failed_jobs', 'email_handoff_uncertain', 'email_queue_backlog'];

    private const SCANNER_MAX_AGE_SECONDS = 150;

    private function conditions(array $health): array
    {
        $components = collect($health['components'] ?? [])->keyBy('component');
        $queue = $health['queue'] ?? [];
        $worker = $components->get('queue_worker')['state'] ?? 'unknown';
        $scheduler = $components->get('scheduler')['state'] ?? 'unknown';
        $available = ($queue['state'] ?? null) === 'available';
        $ready = $available ? ($queue['ready'] ?? null) : null;
        $age = $available ? ($queue['oldest_ready_age_seconds'] ?? null) : null;
        $failed = $available ? ($queue['failed'] ?? null) : null;
        $email = $health['email_handoff'] ?? [];
        $emailReadable = ($email['state'] ?? null) === 'available';
        $unknown = $email['outcome_unknown'] ?? null;
        $stale = $email['stale_claimed'] ?? null;
        $overdue = $email['overdue_queued'] ?? null;

        return [
            'queue_worker_stale' => in_array($worker, ['recent', 'stale'], true) ? $worker === 'stale' : null,
            'scheduler_stale' => in_array($scheduler, ['recent', 'stale'], true) ? $scheduler === 'stale' : null,
            // A positive queue age without a matching, known ready count is not proof of a backlog.
            'queue_backlog' => is_int($ready) && ($ready === 0 || is_int($age))
                ? $ready >= 25 && $age >= 300 : null,
            'queue_failed_jobs' => is_int($failed) ? $failed > 0 : null,
            'email_handoff_uncertain' => $emailReadable && is_int($unknown) && is_int($stale)
                ? $unknown > 0 || $stale > 0 : null,
            'email_queue_backlog' => $emailReadable && is_int($overdue) ? $overdue > 0 : null,
        ];
    }

    public function scan(OperationalHealth $health): array
    {
        if (! config('operational_health.enabled')) {
            return ['skipped' => true, 'opened' => 0, 'resolved' => 0, 'uncertain' => count(self::KEYS), 'external_actions' => false];
        }

        return DB::transaction(function () use ($health) {
            $lock = DB::table('operational_incident_scan_locks')->where('id', 1)->lockForUpdate()->first();
            if (! $lock) {
                throw new \RuntimeException('Operational incident scanner is not initialized.');
            }
            // Collect inside the serialized lock so concurrent scanners do not use
            // an earlier snapshot to reverse a fresher transition.
            $snapshot = $health->report();
            $conditions = $this->conditions($snapshot);
            $result = ['skipped' => false, 'opened' => 0, 'resolved' => 0, 'uncertain' => 0, 'external_actions' => false];
            $now = now('UTC');

            foreach (self::KEYS as $key) {
                $present = $conditions[$key];
                if ($present === null) {
                    $result['uncertain']++;

                    continue;
                }
                $state = DB::table('operational_incident_states')->where('condition_key', $key)->lockForUpdate()->first();
                if (! $state && ! $present) {
                    continue;
                }
                $next = $present ? 'open' : 'resolved';
                if ($state && $state->state === $next) {
                    continue;
                }
                if ($state) {
                    DB::table('operational_incident_states')->where('condition_key', $key)->update([
                        'state' => $next,
                        'transitions' => (int) $state->transitions + 1,
                        'opened_at' => $present ? $now : $state->opened_at,
                        'resolved_at' => $present ? null : $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('operational_incident_states')->insert([
                        'condition_key' => $key, 'state' => 'open', 'transitions' => 1,
                        'opened_at' => $now, 'resolved_at' => null, 'updated_at' => $now,
                    ]);
                }
                DB::table('operational_incident_events')->insert([
                    'condition_key' => $key, 'transition' => $present ? 'opened' : 'resolved',
                    'observed_at' => $now,
                ]);
                $result[$present ? 'opened' : 'resolved']++;
            }

            // Only a fully completed, committed scan refreshes scanner liveness.
            // A separately supervised watchdog never writes this timestamp.
            DB::table('operational_incident_scan_locks')->where('id', 1)
                ->update(['last_completed_at' => $now]);

            return $result;
        }, 3);
    }

    /** Read-only, bounded and deliberately free of queue payloads or recipient data. */
    public function summary(): array
    {
        try {
            // A missing incident table must abort only this nested savepoint,
            // not poison the enclosing PostgreSQL Admin transaction.
            return DB::transaction(function () {
                // Partition one result so a concurrent scan cannot make a
                // condition appear in both projections in the same response.
                $states = DB::table('operational_incident_states')->whereIn('condition_key', self::KEYS)
                    ->orderBy('condition_key')->get(['condition_key', 'state', 'opened_at', 'resolved_at', 'updated_at']);

                return [
                    'store_readable' => true,
                    'monitoring_enabled' => (bool) config('operational_health.enabled'),
                    'automatic_retry' => false,
                    'external_alert_delivery' => false,
                    'production_recovery_verified' => false,
                    'scanner' => $this->scannerSnapshot(),
                    'open' => $states->where('state', 'open')->values()->all(),
                    'resolved' => $states->where('state', 'resolved')->whereNotNull('resolved_at')->values()->all(),
                    'recent_events' => DB::table('operational_incident_events')->orderByDesc('id')->limit(20)
                        ->get(['id', 'condition_key', 'transition', 'observed_at'])->toArray(),
                ];
            });
        } catch (Throwable) {
            return ['store_readable' => false, 'monitoring_enabled' => (bool) config('operational_health.enabled'),
                'automatic_retry' => false, 'external_alert_delivery' => false,
                'production_recovery_verified' => false, 'scanner' => $this->unknownScanner(),
                'open' => [], 'resolved' => [], 'recent_events' => []];
        }
    }

    private function unknownScanner(): array
    {
        return ['state' => 'unknown', 'last_completed_at' => null,
            'max_age_seconds' => self::SCANNER_MAX_AGE_SECONDS];
    }

    /** Liveness of committed scan cycles, never proof that every metric was known. */
    private function scannerSnapshot(): array
    {
        $row = DB::table('operational_incident_scan_locks')->where('id', 1)->first(['last_completed_at']);
        if (! $row || $row->last_completed_at === null) {
            return $this->unknownScanner();
        }
        try {
            $observed = CarbonImmutable::parse($row->last_completed_at, 'UTC');
            $now = CarbonImmutable::now('UTC');
            if ($observed->lte($now)) {
                return ['state' => $observed->gt($now->subSeconds(self::SCANNER_MAX_AGE_SECONDS)) ? 'recent' : 'stale',
                    'last_completed_at' => $observed->toISOString(),
                    'max_age_seconds' => self::SCANNER_MAX_AGE_SECONDS];
            }
        } catch (Throwable) {
            // Future or malformed evidence must not imply a healthy scanner.
        }

        return $this->unknownScanner();
    }
}
