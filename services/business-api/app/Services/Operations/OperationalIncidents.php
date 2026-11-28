<?php

namespace App\Services\Operations;

use App\Models\InboxNotification;
use App\Models\User;
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
            $present = array_keys(array_filter($conditions, static fn (?bool $condition): bool => $condition === true));
            $willOpen = $present && DB::table('operational_incident_states')->whereIn('condition_key', $present)
                ->where('state', 'open')->count() < count($present);
            // Admin review and preference updates lock the current account first.
            // Take recipient locks before any incident row so scan and review
            // never form an account/incident lock cycle.
            $recipients = $willOpen ? $this->lockSubscribers() : [];
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
                if ($present) {
                    $this->notifySubscribers($recipients, $key, $state ? (int) $state->transitions + 1 : 1);
                }
                $result[$present ? 'opened' : 'resolved']++;
            }

            // Only a fully completed, committed scan refreshes scanner liveness.
            // A separately supervised watchdog never writes this timestamp.
            DB::table('operational_incident_scan_locks')->where('id', 1)
                ->update(['last_completed_at' => $now]);

            return $result;
        }, 3);
    }

    public function subscribed(User $actor): bool
    {
        return DB::table('operational_incident_subscriptions')
            ->where('user_id', $actor->id)->where('auth_epoch', $actor->auth_epoch)->exists();
    }

    public function subscribe(User $actor, bool $enabled): bool
    {
        abort_unless(DB::transactionLevel() > 0 && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);
        if ($this->subscribed($actor) === $enabled) {
            return $enabled;
        }

        $identity = ['user_id' => $actor->id];
        if ($enabled) {
            DB::table('operational_incident_subscriptions')->updateOrInsert($identity, ['auth_epoch' => $actor->auth_epoch]);
        } else {
            DB::table('operational_incident_subscriptions')->where($identity)->delete();
        }
        DB::table('audit_events')->insert([
            'actor_id' => $actor->id,
            'action' => $enabled ? 'operations.incident_alerts_enabled' : 'operations.incident_alerts_disabled',
            'subject' => (string) $actor->id,
            'created_at' => now('UTC'),
        ]);

        return $enabled;
    }

    private function lockSubscribers(): array
    {
        $recipients = [];
        DB::table('operational_incident_subscriptions')->select('user_id')->chunkById(100, function ($subscriptions) use (&$recipients) {
            foreach ($subscriptions as $subscription) {
                $user = User::whereKey($subscription->user_id)->lockForUpdate()->first();
                if ($user && $user->role === 'admin' && $user->account_status === 'active' && $this->subscribed($user)) {
                    $recipients[] = $user->id;
                }
            }
        }, 'user_id');

        return $recipients;
    }

    private function notifySubscribers(array $recipients, string $key, int $revision): void
    {
        // Each opening, timeline event and alert commit under the same scan lock.
        foreach ($recipients as $recipient) {
            InboxNotification::create([
                'user_id' => $recipient,
                'kind' => InboxNotification::OPERATIONAL_INCIDENT_OPENED,
                'metadata' => ['condition_key' => $key, 'transition_revision' => $revision],
            ]);
        }
    }

    /**
     * Acknowledge review of an exact open occurrence, never resolve the source
     * incident or change queue, provider, payment or charger state.
     * The caller holds the current Admin account lock before this state lock.
     */
    public function acknowledge(User $actor, string $key, int $revision): array
    {
        abort_unless(DB::transactionLevel() > 0 && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);
        abort_unless(in_array($key, self::KEYS, true), 422);
        $state = DB::table('operational_incident_states')->where('condition_key', $key)->lockForUpdate()->first();
        abort_unless($state && $state->state === 'open' && (int) $state->transitions === $revision, 409, 'Incident occurrence changed. Refresh the observations.');
        $identity = ['condition_key' => $key, 'transition_revision' => $revision];
        $record = DB::table('operational_incident_acknowledgements')->where($identity)->first();
        if (! $record) {
            $at = now('UTC');
            DB::table('operational_incident_acknowledgements')->insert($identity + ['acknowledged_at' => $at]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id, 'action' => 'operations.incident_acknowledged',
                'subject' => $key.':'.$revision, 'created_at' => $at,
            ]);
            $record = DB::table('operational_incident_acknowledgements')->where($identity)->firstOrFail();
        }

        return $identity + ['acknowledged_at' => $record->acknowledged_at,
            'incident_state' => 'open', 'external_actions' => false];
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
                $states = DB::table('operational_incident_states as incident')
                    ->leftJoin('operational_incident_acknowledgements as review', function ($join) {
                        $join->on('review.condition_key', '=', 'incident.condition_key')
                            ->on('review.transition_revision', '=', 'incident.transitions');
                    })
                    ->whereIn('incident.condition_key', self::KEYS)->orderBy('incident.condition_key')
                    ->get(['incident.condition_key', 'incident.state', 'incident.opened_at',
                        'incident.resolved_at', 'incident.updated_at', 'incident.transitions as transition_revision',
                        'review.acknowledged_at'])
                    ->map(function ($state) {
                        $state->transition_revision = (int) $state->transition_revision;

                        return $state;
                    });

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
