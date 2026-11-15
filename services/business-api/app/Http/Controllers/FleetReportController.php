<?php

namespace App\Http\Controllers;

use App\Models\Fleet;
use App\Models\User;
use App\Services\Enterprise\FleetReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FleetReportController extends Controller
{
    public function show(Request $request, Fleet $fleet, FleetReport $reports)
    {
        $filters = $request->validate(['from' => 'required|date_format:Y-m-d', 'through' => 'required|date_format:Y-m-d', 'cost_center_id' => 'nullable|integer|min:1', 'format' => 'nullable|in:json,csv']);
        $data = DB::transaction(function () use ($request, $fleet, $reports, $filters) {
            $snapshot = $request->user();
            $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->account_status === 'active', 403);
            abort_unless($snapshot->role === $current->role && (int) $snapshot->auth_epoch === (int) $current->auth_epoch && hash_equals((string) $snapshot->password, (string) $current->password), 409, 'Account credentials changed; authenticate again.');
            $locked = Fleet::whereKey($fleet->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->owner_id === $current->id || $current->role === 'admin', 404);

            return $reports->read($locked, $filters);
        });
        if (($filters['format'] ?? 'json') !== 'csv') {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }
        $file = fopen('php://temp', 'w+');
        $write = static function (array $cells) use ($file): void {
            fputcsv($file, array_map(static fn ($v) => preg_match('/^[\s\x{FEFF}]*[=+@-]/u', (string) $v) ? "'".$v : (string) $v, $cells), ',', '"', '');
        };
        $write(['Fleet report', $data['fleet']['name'], $data['as_of']]);
        $write(['Approved claims submitted from', $data['period']['from'], 'through', $data['period']['through'], $data['period']['timezone']]);
        $write(['Recorded approved claim amounts only; not a balance, payment, refund total or proof of physical charging.']);
        foreach ($data['current_inventory'] as $key => $value) {
            $write(['Current inventory', $key, $value]);
        }
        $write(['Cost center', 'Historic name', 'Currency', 'Minor units', 'Claims', 'Recorded amount in minor units']);
        foreach ($data['groups'] as $group) {
            $write([$group['code'], $group['name'], $group['currency'], $group['minor_units'], $group['claims'], $group['amount_minor']]);
        }
        rewind($file);
        $csv = stream_get_contents($file);
        fclose($file);

        return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="fleet-report.csv"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
