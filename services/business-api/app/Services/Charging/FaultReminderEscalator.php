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
        $lastId = $after;
        foreach ($ids as $id) {
            $id = (int) $id;
            $lastId = $id;
            $examined++;
            $created = DB::transaction(function () use ($id, $cutoff): bool {
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
                if (! $alert || $alert->acknowledged_at !== null
                    || $alert->recovery_observation_id !== null) {
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
                // A malformed retained source timestamp cannot terminate the
                // bounded scan or starve other vendors. Never infer a valid
                // overdue fault from an unparsable historical observation.
                try {
                    $occurredAt = CarbonImmutable::parse($observation->occurred_at);
                } catch (Throwable) {
                    return false;
                }
                if ($occurredAt->greaterThan($cutoff)) {
                    return false;
                }
                $preference = DB::table('notification_preferences')
                    ->where('user_id', $company->owner_id)->lockForUpdate()->first();
                if ($preference && ! (bool) $preference->remind_unresolved_simulator_faults) {
                    return false;
                }
                // Per-owner delay can only lengthen the worker's global
                // minimum. A corrupt value fails closed; it never shortens
                // the reminder interval or exposes another owner's notice.
                $delay = (int) ($preference->fault_reminder_delay_minutes ?? 15);
                if (! in_array($delay, [15, 30, 60], true)
                    || $occurredAt->greaterThan(now()->subMinutes($delay))) {
                    return false;
                }
                $inserted = DB::table('charging_fault_reminders')->insertOrIgnore([
                    'alert_id' => $alert->id,
                    'user_id' => $company->owner_id,
                    'created_at' => now(),
                ]);
                if ($inserted !== 1) {
                    return false;
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

                return true;
            }, 3);
            if ($created) {
                $reminded++;
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
            'after' => $lastId,
            'in_app_only' => true,
            'physical_verification' => false,
            'commands_sent' => false,
            'capacity_changed' => false,
        ];
    }
}
