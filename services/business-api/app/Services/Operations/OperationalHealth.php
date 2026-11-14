<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Process observations and aggregate queue counts, never payloads or uptime claims. */
final class OperationalHealth
{
    private array $lastAttempt = [];

    private function scope(string $component): string
    {
        return hash('sha256', json_encode($component === 'queue_worker' ? ['queue_worker', config('queue.default'), $this->queueName()] : ['scheduler'], JSON_THROW_ON_ERROR));
    }

    private function queueName(): string
    {
        return (string) config('queue.connections.'.config('queue.default').'.queue', 'default');
    }

    public function record(string $component): bool
    {
        if (! config('operational_health.enabled')) {
            return false;
        }
        abort_unless(in_array($component, ['queue_worker', 'scheduler'], true), 422);
        $scope = $this->scope($component);
        $now = CarbonImmutable::now('UTC');
        $key = $component.':'.$scope;
        if (isset($this->lastAttempt[$key]) && $now->getTimestamp() >= $this->lastAttempt[$key] && $now->getTimestamp() - $this->lastAttempt[$key] < 30) {
            return false;
        }
        $this->lastAttempt[$key] = $now->getTimestamp();
        try {
            DB::transaction(fn () => DB::table('operational_heartbeats')->upsert([['component' => $component, 'scope_hash' => $scope, 'observed_at' => $now->toISOString()]], ['component'], ['scope_hash', 'observed_at']));

            return true;
        } catch (\Throwable) {
            // Monitoring must not stop a worker or leak SQL/credentials into logs.
            Log::warning('operational_health.observation_unavailable');

            return false;
        }
    }

    public function workerLoop(Looping $event): void
    {
        if ($event->connectionName !== config('queue.default')) {
            return;
        }
        $queues = is_string($event->queue) ? array_map('trim', explode(',', $event->queue)) : [];
        if (in_array($this->queueName(), $queues, true)) {
            $this->record('queue_worker');
        }
    }

    private function metrics(CarbonImmutable $now): array
    {
        $unknown = ['state' => 'unavailable', 'ready' => null, 'reserved' => null, 'delayed' => null, 'failed' => null, 'oldest_ready_age_seconds' => null];
        $connection = (string) config('queue.default');
        $config = config('queue.connections.'.$connection, []);
        if (($config['driver'] ?? null) !== 'database') {
            return array_replace($unknown, ['state' => 'unsupported']);
        }
        try {
            $queueDatabase = DB::connection($config['connection'] ?? null);

            return $queueDatabase->transaction(function () use ($queueDatabase, $config, $connection, $now) {
                $jobs = $queueDatabase->table($config['table'] ?? 'jobs')->where('queue', $this->queueName());
                $ready = (clone $jobs)->whereNull('reserved_at')->where('available_at', '<=', $now->getTimestamp());
                $created = (clone $ready)->min('created_at');
                $failed = null;
                if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
                    $failedDatabase = DB::connection(config('queue.failed.database'));
                    $failed = $failedDatabase->transaction(fn () => $failedDatabase->table(config('queue.failed.table', 'failed_jobs'))
                        ->where('connection', $connection)->where('queue', $this->queueName())->count());
                }

                return ['state' => 'available', 'ready' => (clone $ready)->count(), 'reserved' => (clone $jobs)->whereNotNull('reserved_at')->count(),
                    'delayed' => (clone $jobs)->whereNull('reserved_at')->where('available_at', '>', $now->getTimestamp())->count(), 'failed' => $failed,
                    'oldest_ready_age_seconds' => $created !== null && (int) $created <= $now->getTimestamp() ? $now->getTimestamp() - (int) $created : null];
            });
        } catch (\Throwable) {
            return $unknown;
        }
    }

    public function report(): array
    {
        $now = CarbonImmutable::now('UTC');
        $enabled = (bool) config('operational_health.enabled');
        $rows = collect();
        $readable = $enabled;
        if ($enabled) {
            try {
                $rows = DB::transaction(fn () => DB::table('operational_heartbeats')->whereIn('component', ['queue_worker', 'scheduler'])->get()->keyBy('component'));
            } catch (\Throwable) {
                $readable = false;
            }
        }
        $components = [];
        foreach (['queue_worker', 'scheduler'] as $name) {
            $state = $enabled ? 'unknown' : 'disabled';
            $at = null;
            $row = $rows->get($name);
            if ($readable && $row && hash_equals($this->scope($name), $row->scope_hash)) {
                try {
                    $observed = CarbonImmutable::parse($row->observed_at, 'UTC');
                    if ($observed->lte($now)) {
                        $at = $observed->toISOString();
                        $state = $observed->gt($now->subSeconds(150)) ? 'recent' : 'stale';
                    }
                } catch (\Throwable) {
                    // Invalid or future telemetry remains unknown.
                }
            }
            $components[] = ['component' => $name, 'state' => $state, 'observed_at' => $at];
        }

        return ['as_of' => $now->toISOString(), 'enabled' => $enabled, 'heartbeat_store_readable' => $readable, 'max_age_seconds' => 150,
            'scope' => 'default_queue_and_scheduler_observations', 'components' => $components,
            'queue' => $enabled ? $this->metrics($now) : ['state' => 'disabled', 'ready' => null, 'reserved' => null, 'delayed' => null, 'failed' => null, 'oldest_ready_age_seconds' => null],
            'all_workers_verified' => false, 'job_success_verified' => false, 'charger_connectivity_verified' => false, 'production_readiness_verified' => false];
    }
}
