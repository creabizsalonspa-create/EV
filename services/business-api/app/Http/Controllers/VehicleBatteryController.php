<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleBatteryController extends Controller
{
    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('vehicle_battery.simulator_enabled') === true;
    }

    private function owner(Request $request, Vehicle $vehicle): void
    {
        abort_unless($vehicle->user_id === $request->user()->id && $request->user()->role === 'customer', 404);
    }

    private function preferences(Vehicle $vehicle): array
    {
        return ['vehicle_id' => $vehicle->id, 'connector_type' => $vehicle->connector_type,
            'alerts_enabled' => (bool) $vehicle->battery_alerts_enabled,
            'threshold_percent' => (int) $vehicle->battery_alert_threshold,
            'version' => (int) $vehicle->battery_preferences_version,
            'can_simulate' => self::enabled(), 'telemetry_source' => 'SIMULATOR_ONLY'];
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        $this->owner($request, $vehicle);

        return response()->json($this->preferences($vehicle))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $this->owner($request, $vehicle);
        $v = $request->validate(['alerts_enabled' => 'required|boolean', 'threshold_percent' => 'required|integer|between:1,50', 'expected_version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($request, $vehicle, $v) {
            $row = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $row->battery_preferences_version === (int) $v['expected_version'], 409, 'Preferences changed. Refresh before saving.');
            $row->update(['battery_alerts_enabled' => $v['alerts_enabled'], 'battery_alert_threshold' => $v['threshold_percent'], 'battery_preferences_version' => $row->battery_preferences_version + 1]);
            DB::table('audit_events')->insert(['actor_id' => $request->user()->id, 'action' => 'vehicle.battery.preferences', 'subject' => (string) $vehicle->id, 'created_at' => now()]);

            return response()->json($this->preferences($row))->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    private function report(object $row): array
    {
        return ['source' => 'simulator', 'notification_id' => $row->notification_id,
            'alerted' => $row->notification_id !== null, 'battery_percent' => (int) $row->battery_percent,
            'threshold_percent' => (int) $row->threshold_percent,
            'alerts_enabled' => (bool) $row->alerts_enabled, 'preferences_version' => (int) $row->preferences_version];
    }

    public function simulate(Request $request, Vehicle $vehicle)
    {
        $this->owner($request, $vehicle);
        abort_unless(self::enabled(), 403, 'Battery simulator is unavailable.');
        $values = $request->validate(['operation_key' => 'required|uuid', 'battery_percent' => 'required|integer|min:0|max:100']);

        return DB::transaction(function () use ($request, $vehicle, $values) {
            $locked = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $report = DB::table('vehicle_battery_reports')->where('vehicle_id', $vehicle->id)->where('operation_key', $values['operation_key'])->first();
            if ($report) {
                abort_unless((int) $report->battery_percent === (int) $values['battery_percent'], 409, 'Operation already used for a different observation.');

                return response()->json($this->report($report))->header('Cache-Control', 'private, no-store');
            }
            $notification = null;
            if ($locked->battery_alerts_enabled && (int) $values['battery_percent'] <= $locked->battery_alert_threshold) {
                $notification = InboxNotification::create(['user_id' => $request->user()->id,
                    'kind' => 'vehicle.battery.simulated', 'metadata' => ['source' => 'simulator',
                        'vehicle_id' => $vehicle->id, 'battery_percent' => (int) $values['battery_percent']]]);
            }
            $id = DB::table('vehicle_battery_reports')->insertGetId([
                'vehicle_id' => $vehicle->id, 'operation_key' => $values['operation_key'],
                'battery_percent' => $values['battery_percent'], 'notification_id' => $notification?->id,
                'alerts_enabled' => (bool) $locked->battery_alerts_enabled,
                'threshold_percent' => $locked->battery_alert_threshold,
                'preferences_version' => $locked->battery_preferences_version,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert(['actor_id' => $request->user()->id, 'action' => 'vehicle.battery.simulated', 'subject' => (string) $vehicle->id, 'created_at' => now()]);

            return response()->json($this->report(DB::table('vehicle_battery_reports')->find($id)), 201)->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
