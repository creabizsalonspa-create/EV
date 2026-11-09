<?php

namespace App\Services\Charging;

use App\Models\InboxNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One optional in-app reminder for a genuinely unresolved simulator fault.
 *
 * This class reads durable local source evidence. It never polls a charger,
 * sends external messages, changes availability or mutates a charging session.
 */
class FaultReminderEscalator
{
    /**
     * Interpret only complete, explicit source event timestamps. Carbon's
     * permissive parser accepts relative words and normalizes invalid dates;
     * neither is durable evidence of a physical/source state transition.
     */
    private static function sourceTimestamp(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw)
            || preg_match(
                '/\\A\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}(?::?\\d{2})?)?\\z/',
                $raw
            ) !== 1) {
            return null;
        }

        try {
            $timestamp = CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }

        // Reject parser rollover (such as February 30 or hour 24) while
        // preserving valid SQLite and PostgreSQL offsets/fractional seconds.
        return $timestamp->format('Y-m-d H:i:s') === str_replace('T', ' ', substr($raw, 0, 19))
            ? $timestamp : null;
    }

    public function scan(int $limit = 50, int $after = -1, int $olderThanMinutes = 15): array
    {
        abort_unless(app()->environment(['local', 'testing'])
            && config('csms.session_bridge_enabled')
            && config('csms.fault_worker_enabled'), 503);
        abort_unless($limit >= 1 && $limit <= 100
            && $after >= -1 && $olderThanMinutes >= 5
            && $olderThanMinutes <= 1440, 422);

        $cutoff = now()->subMinutes($olderThanMinutes);
        $automatic = $after === -1;
        if ($automatic) {
            DB::table('charging_fault_reminder_scan_progress')->insertOrIgnore([
                'id' => 1, 'after_alert_id' => 0, 'updated_at' => now(),
            ]);
            $after = (int) DB::table('charging_fault_reminder_scan_progress')
                ->where('id', 1)->value('after_alert_id');
        }
        $originalCursor = $after;
        // When the cursor has reached the highest ID, wrap and consume the
        // first bounded page during THIS run. Otherwise an opted-out alert
        // cannot be revisited immediately after its owner opts back in.
        // A rotating cursor scans EVERY alert by ID, not only eligible ones.
        // Otherwise old opt-outs or no-longer-owned alerts can starve all
        // newer operators. Eligibility is rechecked after locking each row.
        $ids = DB::table('charging_fault_alerts')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        if ($automatic && $ids->isEmpty() && $after > 0) {
            $after = 0;
            $ids = DB::table('charging_fault_alerts')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');
        }

        $examined = 0;
        $reminded = 0;
        $escalated = 0;
        $closed = 0;
        $lastId = $after;
        foreach ($ids as $id) {
            $id = (int) $id;
            $lastId = $id;
            $examined++;
            $result = DB::transaction(function () use ($id, $cutoff) {
                // Lookup is only a hint; all ownership, binding, preference,
                // source generation and fault state checks happen again below
                // under locks. Lock order matches existing vendor fault writes.
                $hint = DB::table('charging_fault_alerts as alert')
                    ->join('charging_status_observations as observation',
                        'observation.id', '=', 'alert.observation_id')
                    ->join('csms_bindings as binding', 'binding.id', '=', 'observation.binding_id')
                    ->join('vendor_chargers as charger', 'charger.id', '=', 'binding.vendor_charger_id')
                    ->select('binding.id as binding_id', 'charger.company_id')
                    ->where('alert.id', $id)->first();
                if (! $hint) {
                    return false;
                }
                $company = DB::table('vendor_companies')
                    ->where('id', $hint->company_id)->lockForUpdate()->first();
                $binding = DB::table('csms_bindings')
                    ->where('id', $hint->binding_id)->lockForUpdate()->first();
                if (! $company || ! $binding || ! $binding->vendor_charger_id) {
                    return false;
                }
                $charger = DB::table('vendor_chargers')
                    ->where('id', $binding->vendor_charger_id)->lockForUpdate()->first();
                if (! $charger || $charger->company_id !== $company->id) {
                    return false;
                }
                $site = DB::table('vendor_sites')
                    ->where('id', $charger->site_id)->lockForUpdate()->first();
                $station = DB::table('stations')
                    ->where('id', $binding->station_id)->lockForUpdate()->first();
                if (! $site || ! $station || $site->company_id !== $company->id
                    || $site->station_id !== $station->id
                    || $station->vendor_id !== $company->owner_id
                    || $station->source !== 'simulator'
                    || $charger->source !== 'simulator'
                    || $charger->ocpp_protocol !== $binding->protocol
                    || ! in_array($binding->protocol, ['1.6', '2.0.1'], true)
                    || ! str_starts_with($binding->identifier, 'ev-test-')
                    || DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->count() !== 1
                    || DB::table('csms_bindings')->where('identifier', $binding->identifier)->count() !== 1
                    || ! DB::table('users')->where('id', $company->owner_id)->where('role', 'vendor')->exists()) {
                    return false;
                }
                $alert = DB::table('charging_fault_alerts')
                    ->where('id', $id)->lockForUpdate()->first();
                if (! $alert) {
                    return false;
                }
                $observation = DB::table('charging_status_observations')
                    ->where('id', $alert->observation_id)->first();
                if (! $observation || $observation->binding_id !== $binding->id
                    || ! hash_equals(
                        FaultMonitor::generation($binding),
                        (string) $observation->source_generation
                    )) {
                    return false;
                }
                $occurredAt = self::sourceTimestamp($observation->occurred_at);
                if ($occurredAt === null) {
                    return false;
                }
                // An on-call escalation is a historical incident record.
                // Reconcile its status when the original fault is explicitly
                // acknowledged or authentic same-generation recovery is
                // observed. Neither event proves physical repair.
                if ($alert->acknowledged_at !== null ||
                    $alert->recovery_observation_id !== null) {
                    $resolution = null;
                    if ($alert->recovery_observation_id !== null) {
                        $recovered = DB::table('charging_status_observations')
                            ->where('id', $alert->recovery_observation_id)->first();
                        $recoveredAt = null;
                        // Relative text and calendar-overflow dates must
                        // never become a fresh source-recovery observation.
                        if ($recovered) {
                            $recoveredAt = self::sourceTimestamp($recovered->occurred_at);
                        }
                        if ($recovered && $recoveredAt !== null &&
                            $recoveredAt->greaterThan($occurredAt) &&
                            $recoveredAt->lessThanOrEqualTo(now()) &&
                            $recovered->binding_id === $binding->id &&
                            hash_equals((string) $observation->source_generation,
                                (string) $recovered->source_generation) &&
                            $recovered->source_event_id > $observation->source_event_id &&
                            $recovered->evse_number === $observation->evse_number &&
                            $recovered->connector_number === $observation->connector_number &&
                            $recovered->status === 'Available' &&
                            in_array($recovered->error_code, [null, 'NoError'], true)) {
                            $resolution = 'source_recovered';
                        }
                    }
                    if ($resolution === null && $alert->acknowledged_at !== null) {
                        $resolution = 'operator_acknowledged';
                    }
                    if ($resolution === null) {
                        return false;
                    }
                    $prior = DB::table('charging_fault_admin_escalations')
                        ->where('alert_id', $alert->id)->lockForUpdate()->first();
                    if (! $prior || $prior->resolved_at !== null) {
                        return false;
                    }
                    $updated = DB::table('charging_fault_admin_escalations')
                        ->where('alert_id', $alert->id)->whereNull('resolved_at')
                        ->update(['resolved_at' => now(), 'resolution_source' => $resolution]);
                    if ($updated === 1) {
                        // The prior escalation is a durable incident assigned
                        // to its original Admin recipient. Preserve a matching
                        // closure even if the role was revoked in the interim.
                        // The inbox visibility SQL hides both events until
                        // the recipient's CURRENT Admin role is restored.
                        InboxNotification::create([
                            'user_id' => $prior->admin_user_id,
                            'kind' => 'charging.fault.escalation_closed.simulated',
                            'metadata' => [
                                'source' => 'simulator',
                                'evidence' => $resolution === 'source_recovered'
                                    ? 'SIMULATED_SOURCE_OBSERVED'
                                    : 'SIMULATED_OPERATOR_ACKNOWLEDGED',
                                'physical_verification' => false,
                                'company_id' => $company->id,
                                'station_id' => $station->id,
                                'charger_id' => $charger->id,
                                'alert_id' => $alert->id,
                                'admin_escalation_only' => true,
                                'resolution_source' => $resolution,
                            ],
                        ]);
                    }

                    return ['reminded' => false, 'escalated' => false,
                        'closed' => $updated === 1];
                }
                if ($occurredAt->greaterThan($cutoff)) {
                    return false;
                }
                // A 60+ minute unresolved *simulator* fault can also notify
                // an explicitly designated, currently authorized Admin. The
                // vendor's optional reminder opt-out never controls this
                // independent operational oversight. An unset/invalid Admin
                // ID disables this stage entirely.
                $escalatedNow = false;
                $adminId = filter_var(
                    config('csms.fault_escalation_admin_user_id', 0),
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($adminId !== false &&
                    $occurredAt->lessThanOrEqualTo(now()->subMinutes(60))) {
                    $admin = DB::table('users')->where('id', $adminId)
                        ->lockForUpdate()->first();
                    if ($admin && $admin->role === 'admin') {
                        $insertedEscalation = DB::table('charging_fault_admin_escalations')
                            ->insertOrIgnore([
                                'alert_id' => $alert->id,
                                'admin_user_id' => $adminId,
                                'created_at' => now(),
                            ]);
                        if ($insertedEscalation === 1) {
                            InboxNotification::create([
                                'user_id' => $adminId,
                                'kind' => 'charging.fault.escalated.simulated',
                                'metadata' => [
                                    'source' => 'simulator',
                                    'evidence' => 'SIMULATED_SOURCE_OBSERVED',
                                    'physical_verification' => false,
                                    'company_id' => $company->id,
                                    'station_id' => $station->id,
                                    'charger_id' => $charger->id,
                                    'alert_id' => $alert->id,
                                    'admin_escalation_only' => true,
                                ],
                            ]);
                            $escalatedNow = true;
                        }
                    }
                }
                $preference = DB::table('notification_preferences')
                    ->where('user_id', $company->owner_id)->lockForUpdate()->first();
                if ($preference && ! (bool) $preference->remind_unresolved_simulator_faults) {
                    return ['reminded' => false, 'escalated' => $escalatedNow];
                }
                // Per-owner delay can only lengthen the worker's global
                // minimum. A corrupt value fails closed; it never shortens
                // the reminder interval or exposes another owner's notice.
                $delay = (int) ($preference->fault_reminder_delay_minutes ?? 15);
                if (! in_array($delay, [15, 30, 60], true)
                    || $occurredAt->greaterThan(now()->subMinutes($delay))) {
                    return ['reminded' => false, 'escalated' => $escalatedNow];
                }
                $inserted = DB::table('charging_fault_reminders')->insertOrIgnore([
                    'alert_id' => $alert->id,
                    'user_id' => $company->owner_id,
                    'created_at' => now(),
                ]);
                if ($inserted !== 1) {
                    return ['reminded' => false, 'escalated' => $escalatedNow];
                }
                InboxNotification::create([
                    'user_id' => $company->owner_id,
                    'kind' => 'charging.fault.reminder.simulated',
                    'metadata' => [
                        'source' => 'simulator',
                        'evidence' => 'SIMULATED_SOURCE_OBSERVED',
                        'physical_verification' => false,
                        'company_id' => $company->id,
                        'station_id' => $station->id,
                        'charger_id' => $charger->id,
                        'alert_id' => $alert->id,
                        'reminder_only' => true,
                    ],
                ]);

                return ['reminded' => true, 'escalated' => $escalatedNow];
            }, 3);
            if (is_array($result)) {
                if ($result['reminded']) {
                    $reminded++;
                }
                if ($result['escalated']) {
                    $escalated++;
                }
                if ($result['closed'] ?? false) {
                    $closed++;
                }
            }
        }

        if ($automatic) {
            // CAS avoids a second concurrent scheduler run moving a more
            // advanced cursor backwards. A completed pass wraps to zero.
            $next = $ids->isEmpty() ? 0 : $lastId;
            DB::table('charging_fault_reminder_scan_progress')
                ->where('id', 1)->where('after_alert_id', $originalCursor)
                ->update(['after_alert_id' => $next, 'updated_at' => now()]);
        }

        return [
            'examined' => $examined,
            'reminded' => $reminded,
            'escalated' => $escalated,
            'closed' => $closed,
            'after' => $lastId,
            'in_app_only' => true,
            'physical_verification' => false,
            'commands_sent' => false,
            'capacity_changed' => false,
        ];
    }
}
