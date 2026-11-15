<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\FleetVehicle;
use App\Models\User;
use App\Services\Enterprise\FleetReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FleetReportController extends Controller
{
    private function actor(Request $request): User
    {
        $snapshot = $request->user();
        $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->account_status === 'active', 403);
        abort_unless($snapshot->role === $current->role && (int) $snapshot->auth_epoch === (int) $current->auth_epoch && hash_equals((string) $snapshot->password, (string) $current->password), 409, 'Account credentials changed; authenticate again.');

        return $current;
    }

    public function index(Request $request)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:80',
            'country' => ['nullable', 'regex:/^[A-Z]{2}$/D'],
            'owner_id' => 'nullable|integer|between:1,9007199254740991',
            'page' => 'nullable|integer|between:1,1000000',
        ]);

        return DB::transaction(function () use ($request, $v) {
            $actor = $this->actor($request);
            abort_unless($actor->role === 'admin', 403);

            // Apply the owner filter BEFORE the fixed-size page is selected.
            // Explicit fields only: no fleet owner password, token, or KYC.
            $query = Fleet::query()->select('id', 'name', 'country', 'created_at', 'owner_id');
            $text = trim($v['q'] ?? '');
            if ($text !== '') {
                $operator = match (DB::connection()->getDriverName()) {
                    'pgsql' => 'ILIKE',
                    'sqlite' => 'LIKE',
                    default => throw new \LogicException('Unsupported fleet directory database.'),
                };
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
                $query->whereRaw("name $operator ? ESCAPE '!'", [$pattern]);
            }
            if (isset($v['country'])) {
                $query->where('country', $v['country']);
            }
            if (isset($v['owner_id'])) {
                $query->where('owner_id', (int) $v['owner_id']);
            }

            $page = $query->orderBy('id')->paginate(25);
            $fleetIds = $page->getCollection()->pluck('id');
            $owners = User::query()->whereIn('id', $page->getCollection()->pluck('owner_id'))
                ->get(['id', 'name', 'email'])->keyBy('id');
            $activeMembers = FleetMember::query()->whereIn('fleet_id', $fleetIds)
                ->where('status', 'active')->select('fleet_id')
                ->selectRaw('COUNT(*) as count')->groupBy('fleet_id')
                ->pluck('count', 'fleet_id');
            $assignedVehicles = FleetVehicle::query()->whereIn('fleet_id', $fleetIds)
                ->select('fleet_id')->selectRaw('COUNT(*) as count')
                ->groupBy('fleet_id')->pluck('count', 'fleet_id');
            $costCenters = CostCenter::query()->whereIn('fleet_id', $fleetIds)
                ->select('fleet_id')->selectRaw('COUNT(*) as count')
                ->groupBy('fleet_id')->pluck('count', 'fleet_id');

            $view = $page->through(function (Fleet $fleet) use (
                $owners, $activeMembers, $assignedVehicles, $costCenters
            ) {
                $owner = $owners->get($fleet->owner_id);

                return [
                    'id' => (int) $fleet->id,
                    'name' => $fleet->name,
                    'country' => $fleet->country,
                    'created_at' => $fleet->created_at,
                    'owner' => $owner ? [
                        'id' => (int) $owner->id,
                        'name' => $owner->name,
                        'email' => $owner->email,
                    ] : null,
                    'inventory' => [
                        'active_members' => (int) ($activeMembers->get($fleet->id) ?? 0),
                        'assigned_vehicles' => (int) ($assignedVehicles->get($fleet->id) ?? 0),
                        'cost_centers' => (int) ($costCenters->get($fleet->id) ?? 0),
                    ],
                ];
            });

            return response()->json($view)->header('Cache-Control', 'private, no-store');
        });
    }

    public function show(Request $request, Fleet $fleet, FleetReport $reports)
    {
        $filters = $request->validate(['from' => 'required|date_format:Y-m-d', 'through' => 'required|date_format:Y-m-d', 'cost_center_id' => 'nullable|integer|min:1', 'format' => 'nullable|in:json,csv']);
        $data = DB::transaction(function () use ($request, $fleet, $reports, $filters) {
            $current = $this->actor($request);
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
