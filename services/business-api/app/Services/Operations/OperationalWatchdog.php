<?php

namespace App\Services\Operations;

use Throwable;

/**
 * Strict, read-only watchdog probe intended for a separately supervised caller.
 *
 * No heartbeat is written by this probe, so checking a stopped scheduler
 * cannot make that scheduler appear healthy. No job or hardware commands run.
 */
final class OperationalWatchdog
{
    public function check(OperationalHealth $health, OperationalIncidents $incidents): array
    {
        try {
            $report = $health->report();
            $history = $incidents->summary();
            $signals = [];

            if (($report['enabled'] ?? null) !== true) {
                $signals[] = 'monitoring_disabled';
            }
            if (($report['heartbeat_store_readable'] ?? null) !== true) {
                $signals[] = 'heartbeat_store_unavailable';
            }

            $components = collect($report['components'] ?? [])->keyBy('component');
            foreach (['queue_worker', 'scheduler'] as $component) {
                $state = $components->get($component)['state'] ?? 'unknown';
                if ($state !== 'recent') {
                    $signals[] = $component.'_'.($state === 'stale' ? 'stale' : 'unknown');
                }
            }

            $queue = $report['queue'] ?? [];
            if (($queue['state'] ?? null) !== 'available'
                || ! is_int($queue['ready'] ?? null)
                || ! is_int($queue['failed'] ?? null)
                || ! is_int($queue['reserved'] ?? null)
                || (($queue['ready'] ?? 0) > 0 && ! is_int($queue['oldest_ready_age_seconds'] ?? null))
                || (($queue['reserved'] ?? 0) > 0 && ! is_int($queue['oldest_reserved_age_seconds'] ?? null))) {
                $signals[] = 'queue_metrics_unknown';
            } else {
                if ($queue['failed'] > 0) {
                    $signals[] = 'queue_failed_jobs';
                }
                if ($queue['ready'] >= 25 && $queue['oldest_ready_age_seconds'] >= 300) {
                    $signals[] = 'queue_backlog';
                }
                if ($queue['reserved'] > 0 && $queue['oldest_reserved_age_seconds'] >= 1800) {
                    $signals[] = 'queue_reserved_stale';
                }
            }

            $email = $report['email_handoff'] ?? [];
            if (($email['state'] ?? null) !== 'available'
                || ! is_int($email['outcome_unknown'] ?? null)
                || ! is_int($email['stale_claimed'] ?? null)
                || ! is_int($email['overdue_queued'] ?? null)) {
                $signals[] = 'email_handoff_metrics_unknown';
            } else {
                if ($email['outcome_unknown'] > 0 || $email['stale_claimed'] > 0) {
                    $signals[] = 'email_handoff_needs_review';
                }
                if ($email['overdue_queued'] > 0) {
                    $signals[] = 'email_queue_overdue';
                }
            }

            if (($history['store_readable'] ?? null) !== true) {
                $signals[] = 'incident_store_unavailable';
            } else {
                // Scheduler heartbeat does not prove its incident scan completed.
                // Trust only the timestamp committed after a successful scan.
                $scanner = $history['scanner']['state'] ?? 'unknown';
                if ($scanner !== 'recent') {
                    $signals[] = $scanner === 'stale' ? 'incident_scanner_stale' : 'incident_scanner_unknown';
                }
                if (count($history['open'] ?? []) > 0) {
                    $signals[] = 'recorded_incidents_open';
                }
            }

            return $this->result($signals);
        } catch (Throwable) {
            return $this->result(['watchdog_unavailable']);
        }
    }

    private function result(array $signals): array
    {
        return [
            'status' => $signals ? 'needs_attention' : 'observed_normal',
            'signals' => array_values(array_unique($signals)),
            'read_only' => true,
            'automatic_retry' => false,
            'external_alert_delivery' => false,
            'all_workers_verified' => false,
            'charger_connectivity_verified' => false,
            'production_readiness_verified' => false,
        ];
    }
}
