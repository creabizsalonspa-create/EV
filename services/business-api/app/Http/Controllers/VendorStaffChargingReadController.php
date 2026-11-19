<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Security\AdminTransactionAuthority;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class VendorStaffChargingReadController extends Controller
{
    private const STATUSES = ['pending_start', 'charging', 'stopping', 'stopped', 'cancelled', 'expired_unstarted'];

    public function index(Request $request, VendorCompany $company)
    {
        return DB::transaction(function () use ($request, $company) {
            $snapshot = $request->user();
            abort_unless($snapshot instanceof User && $snapshot->account_status === 'active' && in_array($snapshot->role, ['customer', 'vendor', 'admin'], true), 403);
            if ($snapshot->role === 'admin') {
                $actor = AdminTransactionAuthority::requireCurrent($snapshot);
            } else {
                $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->account_status === 'active', 403);
                abort_unless($actor->role === $snapshot->role && (int) $actor->auth_epoch === (int) $snapshot->auth_epoch && hash_equals((string) $actor->password, (string) $snapshot->password), 409, 'Account authority changed; sign in again.');
            }
            $current = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            app(VendorAccess::class)->require($actor, $current, 'charging.read');
            $v = $request->validate(['page' => 'sometimes|integer|between:1,100000', 'status' => ['sometimes', 'required', Rule::in([...self::STATUSES, 'unknown'])]]);
            $status = $v['status'] ?? null;
            // The site/station relation is unique. Recheck current station
            // ownership and company linkage, never infer scope from session IDs.
            // Fetch no customer, vehicle, reservation or financial columns.
            $query = ChargingSession::query()
                ->join('reservations as booking', 'booking.id', '=', 'charging_sessions.reservation_id')
                ->join('stations as station', 'station.id', '=', 'booking.station_id')
                ->join('vendor_sites as site', 'site.station_id', '=', 'station.id')
                ->leftJoin('csms_session_links as source', 'source.charging_session_id', '=', 'charging_sessions.id')
                ->where('site.company_id', $current->id)->where('station.vendor_id', $current->owner_id)
                ->select(['charging_sessions.id', 'charging_sessions.status', 'charging_sessions.energy_wh', 'charging_sessions.created_at', 'charging_sessions.updated_at', 'charging_sessions.stopped_at',
                    'station.id as station_id', 'station.name as station_name', 'station.source as station_source', 'source.protocol as source_protocol']);
            if ($status === 'unknown') {
                $query->whereNotIn('charging_sessions.status', self::STATUSES);
            } elseif ($status !== null) {
                $query->where('charging_sessions.status', $status);
            }
            $page = $query->orderByDesc('charging_sessions.id')->paginate(25)->through(function ($row): array {
                $energy = $row->energy_wh;
                $readable = is_int($energy) && $energy >= 0 && $energy <= 9007199254740991;
                $protocol = in_array($row->source_protocol, ['1.6', '2.0.1'], true) ? $row->source_protocol : null;

                return [
                    'id' => (int) $row->id, 'station_id' => (int) $row->station_id, 'station_name' => $row->station_name,
                    'recorded_status' => in_array($row->status, self::STATUSES, true) ? $row->status : 'unknown',
                    'energy_wh' => $readable ? $energy : null, 'energy_state' => $readable ? 'recorded' : 'unavailable',
                    'record_created_at' => $row->created_at->toISOString(), 'record_updated_at' => $row->updated_at->toISOString(),
                    'stopped_at' => $row->stopped_at === null ? null : CarbonImmutable::parse($row->stopped_at, 'UTC')->toISOString(),
                    'protocol' => $protocol,
                    'evidence' => $row->station_source === 'simulator' ? ($protocol === null ? 'SIMULATED' : 'SIMULATED_CSMS') : 'RECORDED_SOURCE_UNVERIFIED',
                    'physical_charging_verified' => false, 'live_availability_verified' => false,
                ];
            });

            return response()->json($page->toArray() + [
                'scope' => 'company_recorded_charging_sessions', 'company_id' => (int) $current->id,
                'status_filter' => $status, 'as_of' => now()->toISOString(), 'inventory_station_scope' => 'current_owned_unique_site_links',
                'customer_identity_fields_included' => false, 'financial_fields_included' => false,
                'control_actions_exposed' => false, 'physical_charging_verified' => false, 'live_availability_verified' => false,
            ])->header('Cache-Control', 'no-store, private');
        });
    }
}
