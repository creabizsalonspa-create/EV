<?php

namespace App\Services\Charging;

use App\Models\InboxNotification;
use Illuminate\Support\Facades\DB;

/**
 * One optional in-app reminder for a genuinely unresolved simulator fault.
 *
 * This class reads durable local source evidence. It never polls a charger,
 * sends external messages, changes availability or mutates a charging session.
 */
class FaultReminderEscalator
{
    public function scan(int $limit = 50, int $after = 0, int $olderThanMinutes = 15): array
    {
        abort_unless(app()->environment(['local', 'testing'])
            && config('csms.session_bridge_enabled')
            && config('csms.fault_worker_enabled'), 503);
        abort_unless($limit >= 1 && $limit <= 100
            && $after >= 0 && $olderThanMinutes >= 5
            && $olderThanMinutes <= 1440, 422);

        $cutoff = now()->subMinutes($olderThanMinutes);
        $ids = DB::table('charging_fault_alerts as alert')
            ->join('charging_status_observations as observation', 'observation.id', '=', 'alert.observation_id')
            ->where('alert.id', '>', $after)
            ->whereNull('alert.acknowledged_at')
            ->whereNull('alert.recovery_observation_id')
            ->where('observation.occurred_at', '<=', $cutoff)
            ->orderBy('alert.id')
            ->limit($limit)
            ->pluck('alert.id');

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
                    || $observation->occurred_at > $cutoff
                    || ! hash_equals(
                        FaultMonitor::generation($binding),
                        (string) $observation->source_generation
                    )) {
                    return false;
                }
                $preference = DB::table('notification_preferences')
                    ->where('user_id', $company->owner_id)->lockForUpdate()->first();
                if ($preference && ! (bool) $preference->remind_unresolved_simulator_faults) {
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
