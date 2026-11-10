<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VehicleDriverPreferencesController extends Controller
{
    private function owner(Request $request, Vehicle $vehicle): void
    {
        // A user must not discover vehicle ownership or the existence of private preferences.
        abort_unless($request->user()->role === 'customer'
            && (int) $vehicle->user_id === (int) $request->user()->id, 404);
    }

    private function present(?object $row, int $vehicleId): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'revision' => $row ? (int) $row->revision : 0,
            'preferred_power_kw' => $row && $row->preferred_power_kw !== null
                ? (int) $row->preferred_power_kw : null,
            'charging_goal' => $row->charging_goal ?? 'balanced',
            'accessible_site_required' => $row ? (bool) $row->accessible_site_required : false,
            'avoid_paid_parking' => $row ? (bool) $row->avoid_paid_parking : false,
            'scope' => 'DRIVER_PREFERENCE_ONLY',
            'applied_to_charging' => false,
        ];
    }

    private function respond(array $data)
    {
        return response()->json($data)
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        $this->owner($request, $vehicle);
        $row = DB::table('vehicle_driver_preferences')->where('vehicle_id', $vehicle->id)->first();

        return $this->respond($this->present($row, $vehicle->id));
    }

    public function save(Request $request, Vehicle $vehicle)
    {
        $this->owner($request, $vehicle);

        $v = $request->validate([
            'revision' => 'required|integer|min:0|max:2147483646',
            'preferred_power_kw' => 'present|nullable|integer|between:1,1000',
            'charging_goal' => 'required|in:balanced,fastest,lowest_cost',
            'accessible_site_required' => 'required|boolean',
            'avoid_paid_parking' => 'required|boolean',
        ]);

        return DB::transaction(function () use ($request, $vehicle, $v) {
            // Lock the parent even on first write: concurrent updates to an absent
            // preferences row are serialized instead of racing through INSERT.
            $locked = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->role === 'customer'
                && (int) $locked->user_id === (int) $request->user()->id, 404);
            $prior = DB::table('vehicle_driver_preferences')
                ->where('vehicle_id', $locked->id)->lockForUpdate()->first();
            abort_unless((int) ($prior->revision ?? 0) === (int) $v['revision'],
                409, 'Driver preferences changed; refresh before saving.');

            $values = [
                'revision' => (int) ($prior->revision ?? 0) + 1,
                'preferred_power_kw' => $v['preferred_power_kw'],
                'charging_goal' => $v['charging_goal'],
                'accessible_site_required' => (bool) $v['accessible_site_required'],
                'avoid_paid_parking' => (bool) $v['avoid_paid_parking'],
                'updated_at' => now(),
            ];
            if ($prior) {
                DB::table('vehicle_driver_preferences')->where('vehicle_id', $locked->id)->update($values);
            } else {
                DB::table('vehicle_driver_preferences')->insert($values + [
                    'vehicle_id' => $locked->id,
                    'created_at' => now(),
                ]);
            }
            DB::table('audit_events')->insert([
                'actor_id' => $request->user()->id,
                'action' => 'vehicle.driver_preferences.updated',
                'subject' => (string) $locked->id,
                'created_at' => now(),
            ]);

            return $this->respond($this->present(
                DB::table('vehicle_driver_preferences')->where('vehicle_id', $locked->id)->first(),
                $locked->id
            ));
        }, 3);
    }
}
