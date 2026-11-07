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

    public function simulate(Request $request, Vehicle $vehicle)
    {
        abort_unless($vehicle->user_id === $request->user()->id && $request->user()->role === 'customer', 404);
        abort_unless(self::enabled(), 403, 'Battery simulator is unavailable.');
        $values = $request->validate([
            'operation_key' => 'required|uuid',
            'battery_percent' => 'required|integer|min:0|max:20',
        ]);

        return DB::transaction(function () use ($request, $vehicle, $values) {
            Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $report = DB::table('vehicle_battery_reports')->where('vehicle_id', $vehicle->id)
                ->where('operation_key', $values['operation_key'])->first();
            if ($report) {
                abort_unless($report->battery_percent === (int) $values['battery_percent'], 409, 'Operation already used for a different observation.');

                return response()->json(['source' => 'simulator', 'notification_id' => $report->notification_id, 'battery_percent' => $report->battery_percent]);
            }
            $notification = InboxNotification::create([
                'user_id' => $request->user()->id,
                'kind' => 'vehicle.battery.simulated',
                'metadata' => ['source' => 'simulator', 'vehicle_id' => $vehicle->id, 'battery_percent' => (int) $values['battery_percent']],
            ]);
            DB::table('vehicle_battery_reports')->insert([
                'vehicle_id' => $vehicle->id, 'operation_key' => $values['operation_key'],
                'battery_percent' => $values['battery_percent'], 'notification_id' => $notification->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert(['actor_id' => $request->user()->id, 'action' => 'vehicle.battery.simulated', 'subject' => (string) $vehicle->id, 'created_at' => now()]);

            return response()->json(['source' => 'simulator', 'notification_id' => $notification->id, 'battery_percent' => (int) $values['battery_percent']], 201);
        });
    }
}
