<?php

namespace App\Http\Controllers;

use App\Models\Charging\ConnectorMapping;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MaintenanceWindowController extends Controller
{
    private function scope(Request $r, VendorCompany $company, VendorCharger $charger, VendorConnector $connector, bool $write = false): Station
    {
        app(VendorAccess::class)->require($r->user(), $company, $write ? 'inventory.write' : 'inventory.read');
        abort_unless($charger->company_id === $company->id && $connector->charger_id === $charger->id, 404);
        $site = VendorSite::findOrFail($charger->site_id);
        $station = Station::find($site->station_id);
        abort_unless($site->company_id === $company->id && $station && $station->vendor_id === $company->owner_id && $station->source === 'simulator' && $charger->source === 'simulator', 409, 'Link owned simulator inventory before scheduling admission maintenance.');

        return $station;
    }

    private function publicWindow(object $row): array
    {
        return ['id' => $row->id, 'connector_id' => $row->connector_id, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at, 'reason' => Crypt::decryptString($row->reason), 'cancelled_at' => $row->cancelled_at, 'evidence' => 'SIMULATED_ADMISSION_POLICY', 'commands_sent' => false];
    }

    public function index(Request $r, VendorCompany $company, VendorCharger $charger, VendorConnector $connector)
    {
        $this->scope($r, $company, $charger, $connector);
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json(DB::table('connector_maintenance_windows')->where('connector_id', $connector->id)->orderByDesc('starts_at')->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->publicWindow($row)))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, VendorCompany $company, VendorCharger $charger, VendorConnector $connector)
    {
        $station = $this->scope($r, $company, $charger, $connector, true);
        $v = $r->validate(['starts_at' => 'required|date_format:Y-m-d\TH:i:s\Z', 'ends_at' => 'required|date_format:Y-m-d\TH:i:s\Z|after:starts_at', 'reason' => 'required|string|min:10|max:1000', 'idempotency_key' => 'required|string|max:100']);
        $start = CarbonImmutable::parse($v['starts_at'], 'UTC');
        $end = CarbonImmutable::parse($v['ends_at'], 'UTC');
        $hash = hash('sha256', json_encode([$connector->id, $start->toISOString(), $end->toISOString(), $v['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($r, $company, $charger, $connector, $station, $v, $start, $end, $hash) {
            Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $connector = VendorConnector::whereKey($connector->id)->lockForUpdate()->firstOrFail();
            $current = $this->scope($r, $company, VendorCharger::findOrFail($charger->id), $connector, true);
            abort_unless($current->id === $station->id, 409, 'Inventory changed; refresh.');
            $old = DB::table('connector_maintenance_windows')->where('connector_id', $connector->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Idempotency key payload mismatch.');

                return response()->json($this->publicWindow($old))->header('Cache-Control', 'private, no-store');
            }
            abort_unless($start->isFuture() && $start->lessThan(now()->addYear()) && $end->lessThanOrEqualTo($start->addDays(7)), 422, 'Choose a future UTC maintenance interval of at most seven days within the next year.');
            abort_if(DB::table('connector_maintenance_windows')->where('connector_id', $connector->id)->whereNull('cancelled_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 409, 'Maintenance already occupies this interval.');
            $maps = ConnectorMapping::where('connector_id', $connector->id)->select('id');
            $bookings = Reservation::where('station_id', $station->id)->where('status', 'confirmed')->where('starts_at', '<', $end)->where('starts_at', '>', $start->subMinutes(30))->where(fn ($q) => $q->whereNull('connector_mapping_id')->orWhereIn('connector_mapping_id', $maps));
            abort_if($bookings->exists(), 409, 'Existing reservations overlap; resolve them before scheduling maintenance.');
            $id = DB::table('connector_maintenance_windows')->insertGetId(['connector_id' => $connector->id, 'created_by' => $r->user()->id, 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'starts_at' => $start, 'ends_at' => $end, 'reason' => Crypt::encryptString($v['reason']), 'created_at' => now(), 'updated_at' => now()]);
            app(VendorAccess::class)->audit($r->user(), 'maintenance.scheduled', $id);

            return response()->json($this->publicWindow(DB::table('connector_maintenance_windows')->where('id', $id)->first()), 201)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function cancel(Request $r, VendorCompany $company, VendorCharger $charger, VendorConnector $connector, int $window)
    {
        $station = $this->scope($r, $company, $charger, $connector, true);

        return DB::transaction(function () use ($r, $company, $charger, $connector, $station, $window) {
            Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $connector = VendorConnector::whereKey($connector->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->scope($r, $company, VendorCharger::findOrFail($charger->id), $connector, true)->id === $station->id, 409);
            $row = DB::table('connector_maintenance_windows')->where('id', $window)->where('connector_id', $connector->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->cancelled_at === null) {
                app(VendorAccess::class)->operational($company);
                DB::table('connector_maintenance_windows')->where('id', $row->id)->update(['cancelled_at' => now(), 'updated_at' => now()]);
                app(VendorAccess::class)->audit($r->user(), 'maintenance.cancelled', $row->id);
            }

            return response()->json(['cancelled' => true, 'commands_sent' => false, 'active_sessions_stopped' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
