<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VehicleManualSocController extends Controller
{
    private function owner(Request $r, Vehicle $vehicle): void
    {
        abort_unless($r->user()->role === 'customer'
            && (int) $vehicle->user_id === (int) $r->user()->id, 404);
    }

    private function respond($value, int $status = 200)
    {
        return response()->json($value, $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function display(?object $row, int $vehicleId, bool $replayed = false): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'battery_percent' => $row ? (int) $row->battery_percent : null,
            'revision' => $row ? (int) $row->revision : 0,
            // Source time is server UTC, never an assumed phone/device timezone.
            'reported_at' => $row?->reported_at === null ? null
                : CarbonImmutable::parse($row->reported_at, 'UTC')->utc()->toIso8601String(),
            'source' => 'CUSTOMER_MANUAL_SELF_REPORT',
            'measured_by_vehicle_api' => false,
            'measured_by_phone' => false,
            'charging_capability_verified' => false,
            'replayed' => $replayed,
        ];
    }

    public function show(Request $r, Vehicle $vehicle)
    {
        $this->owner($r, $vehicle);
        $latest = DB::table('vehicle_manual_soc')->where('vehicle_id', $vehicle->id)->first();
        return $this->respond($this->display($latest, $vehicle->id));
    }

    public function history(Request $r, Vehicle $vehicle)
    {
        $this->owner($r, $vehicle);
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        return $this->respond(DB::table('vehicle_manual_soc_reports')
            ->where('vehicle_id', $vehicle->id)->orderByDesc('id')->paginate(25)
            ->through(fn ($row) => $this->display($row, $vehicle->id)));
    }

    public function save(Request $r, Vehicle $vehicle)
    {
        $this->owner($r, $vehicle);
        $v = $r->validate([
            'operation_key' => 'required|uuid',
            'expected_revision' => 'required|integer|min:0|max:2147483646',
            'battery_percent' => 'required|integer|min:0|max:100',
        ]);

        return DB::transaction(function () use ($r, $vehicle, $v) {
            // Parent row lock serializes even the initial report insertion.
            $owned = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $this->owner($r, $owned);
            $oldReport = DB::table('vehicle_manual_soc_reports')
                ->where('vehicle_id', $owned->id)
                ->where('operation_key', $v['operation_key'])->first();
            if ($oldReport) {
                abort_unless((int) $oldReport->battery_percent === (int) $v['battery_percent']
                    && (int) $oldReport->revision === (int) $v['expected_revision'] + 1,
                    409, 'The report operation key belongs to another observation.');
                return $this->respond($this->display($oldReport, $owned->id, true));
            }

            $latest = DB::table('vehicle_manual_soc')
                ->where('vehicle_id', $owned->id)->lockForUpdate()->first();
            abort_unless((int) ($latest->revision ?? 0) === (int) $v['expected_revision'], 409,
                'Battery report changed; refresh before saving.');

            $revision = (int) ($latest->revision ?? 0) + 1;
            $time = now();
            if ($latest) {
                DB::table('vehicle_manual_soc')->where('vehicle_id', $owned->id)->update([
                    'battery_percent' => $v['battery_percent'],
                    'revision' => $revision,
                    'reported_at' => $time,
                    'updated_at' => $time,
                ]);
            } else {
                DB::table('vehicle_manual_soc')->insert([
                    'vehicle_id' => $owned->id,
                    'battery_percent' => $v['battery_percent'],
                    'revision' => $revision,
                    'reported_at' => $time,
                    'created_at' => $time, 'updated_at' => $time,
                ]);
            }

            $reportId = DB::table('vehicle_manual_soc_reports')->insertGetId([
                'vehicle_id' => $owned->id,
                'operation_key' => $v['operation_key'],
                'battery_percent' => $v['battery_percent'],
                'revision' => $revision,
                'reported_at' => $time,
                'created_at' => $time, 'updated_at' => $time,
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $r->user()->id,
                'action' => 'vehicle.manual_soc.reported',
                'subject' => (string) $owned->id,
                'created_at' => $time,
            ]);
            return $this->respond(
                $this->display(DB::table('vehicle_manual_soc_reports')->find($reportId),
                    $owned->id), 201);
        }, 3);
    }
}
