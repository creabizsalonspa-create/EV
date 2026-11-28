<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Security\AdminTransactionAuthority;
use App\Services\Charging\SessionBridge;
use App\Services\Charging\VendorStaffStopAuthority;
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
            $current = $this->authorizedCompany($request, $company);
            $staffStop = app(VendorStaffStopAuthority::class)->grant($request->user(), $current);
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
                ->leftJoin('csms_bindings as stop_binding', 'stop_binding.id', '=', 'source.binding_id')
                ->where('site.company_id', $current->id)->where('station.vendor_id', $current->owner_id)
                ->select(['charging_sessions.id', 'charging_sessions.status', 'charging_sessions.energy_wh', 'charging_sessions.created_at', 'charging_sessions.updated_at', 'charging_sessions.stopped_at',
                    'station.id as station_id', 'station.name as station_name', 'station.source as station_source', 'source.protocol as source_protocol'])
                ->selectRaw('CASE WHEN source.started_at IS NOT NULL AND source.transaction_id IS NOT NULL
                    AND source.connector_mapping_id = booking.connector_mapping_id AND source.stop_command_id IS NULL
                    AND source.ended_at IS NULL AND source.closed_without_dispatch_at IS NULL
                    AND stop_binding.station_id = station.id AND stop_binding.identifier = source.identifier
                    AND stop_binding.protocol = source.protocol AND stop_binding.vendor_charger_id IS NOT NULL
                    AND site.country = station.country AND station.country = ? THEN 1 ELSE 0 END AS staff_stop_ready', [$current->country]);
            if ($status === 'unknown') {
                $query->whereNotIn('charging_sessions.status', self::STATUSES);
            } elseif ($status !== null) {
                $query->where('charging_sessions.status', $status);
            }
            $page = $query->orderByDesc('charging_sessions.id')->paginate(25)->through(function ($row) use ($staffStop): array {
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
                    'can_request_stop' => $staffStop !== null && $row->status === 'charging'
                        && $row->station_source === 'simulator' && $protocol !== null && (bool) $row->staff_stop_ready,
                ];
            });

            if ($status !== null) {
                $page->appends(['status' => $status]);
            }

            return response()->json($page->toArray() + [
                'scope' => 'company_recorded_charging_sessions', 'company_id' => (int) $current->id,
                'status_filter' => $status, 'as_of' => now()->toISOString(), 'inventory_station_scope' => 'current_owned_unique_site_links',
                'customer_identity_fields_included' => false, 'financial_fields_included' => false,
                'control_actions_exposed' => false, 'physical_charging_verified' => false, 'live_availability_verified' => false,
            ] + ($staffStop ? ['staff_stop_grant' => ['id' => $staffStop->id, 'version' => $staffStop->invitation_version]] : []))
                ->header('Cache-Control', 'no-store, private');
        });
    }

    public function stop(Request $request, VendorCompany $company, int $session, SessionBridge $bridge)
    {
        $bridge->enabled();
        $v = $request->validate(['grant_id' => 'required|integer|min:1', 'grant_version' => 'required|integer|min:1', 'consent' => 'required|accepted']);
        $actor = $request->user();
        $summary = $bridge->stop(ChargingSession::findOrFail($session), $actor->id, $actor->role, $actor, [
            'company_id' => $company->id,
            'grant_id' => (int) $v['grant_id'],
            'grant_version' => (int) $v['grant_version'],
        ]);
        $command = $summary['stop_command'];
        abort_unless($command, 409, 'The simulator session can no longer be stopped.');

        return response()->json([
            'scope' => 'company_simulator_session_stop',
            'company_id' => $company->id,
            'session_id' => $session,
            'command_id' => $command->id,
            'command_status' => $command->status,
            'session_status' => $summary['session']->status,
            'evidence' => 'SIMULATED_CSMS',
            'physical_charging_verified' => false,
        ], 202)->header('Cache-Control', 'no-store, private');
    }

    /** Both callers hold this authority through the private projection. */
    private function authorizedCompany(Request $request, VendorCompany $company): VendorCompany
    {
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

        return $current;
    }

    public function meters(Request $request, VendorCompany $company, int $session)
    {
        return DB::transaction(function () use ($request, $company, $session) {
            $current = $this->authorizedCompany($request, $company);
            abort_unless($session > 0 && $session <= 9007199254740991 && $current->id <= 9007199254740991, 422, 'Unsupported recorded-history identity.');
            $v = $request->validate(['page' => 'sometimes|integer|between:1,100000', 'through' => 'sometimes|integer|between:0,9007199254740991']);
            $owned = DB::table('charging_sessions as session')
                ->join('reservations as booking', 'booking.id', '=', 'session.reservation_id')
                ->join('stations as station', 'station.id', '=', 'booking.station_id')
                ->join('vendor_sites as site', 'site.station_id', '=', 'station.id')
                ->where('session.id', $session)->where('site.company_id', $current->id)
                ->where('station.vendor_id', $current->owner_id)
                ->first(['session.id', 'station.id as station_id', 'station.source']);
            abort_unless($owned, 404);
            // Read only the historical link identity, never its private token or
            // transaction identifiers. A foreign/reassigned binding is not proof.
            $link = DB::table('csms_session_links as link')
                ->join('csms_bindings as binding', 'binding.id', '=', 'link.binding_id')
                ->where('link.charging_session_id', $session)->where('binding.station_id', $owned->station_id)
                ->whereIn('link.protocol', ['1.6', '2.0.1'])->first(['link.id', 'link.protocol']);
            $query = DB::table('csms_inbox_events')->when($link !== null, fn ($q) => $q->where('link_id', $link->id), fn ($q) => $q->whereRaw('1 = 0'))
                ->where('outcome', 'applied')->whereIn('type', ['started', 'meter', 'ended']);
            $maximum = (int) ((clone $query)->max('id') ?? 0);
            abort_unless($maximum >= 0 && $maximum <= 9007199254740991, 422, 'Recorded meter history is unreadable.');
            $through = isset($v['through']) ? (int) $v['through'] : $maximum;
            abort_if($through > $maximum, 422, 'Snapshot cursor is beyond retained source history.');
            $rows = $query->where('id', '<=', $through)->orderByDesc('id')
                ->paginate(25, ['id', 'sequence', 'type', 'meter_wh', 'occurred_at', 'created_at'])
                ->through(function ($row) {
                    foreach (['id', 'sequence', 'meter_wh'] as $key) {
                        abort_unless(is_int($row->{$key}) && $row->{$key} >= ($key === 'id' ? 1 : 0) && $row->{$key} <= 9007199254740991, 422, 'Recorded meter history is unreadable.');
                    }

                    return ['id' => $row->id, 'sequence' => $row->sequence, 'kind' => $row->type,
                        'cumulative_meter_wh' => $row->meter_wh,
                        'source_occurred_at' => $this->recordedInstant($row->occurred_at),
                        'recorded_at' => $this->recordedInstant($row->created_at)];
                });

            $rows->appends(['through' => $through]);

            return response()->json(['scope' => 'company_csms_meter_history', 'company_id' => (int) $current->id,
                'session_id' => $session, 'through_id' => $through, 'source_linked' => $link !== null,
                'protocol' => $link?->protocol, 'coverage' => 'RECORDED_APPLIED_CSMS_EVENTS',
                'history_complete' => false, 'billing_authority' => false, 'physical_energy_verified' => false,
                'commands_sent' => false, 'network_requests' => 0,
                'evidence' => $link === null ? 'NO_RECORDED_CSMS_LINK' : ($owned->source === 'simulator' ? 'SIMULATED_CSMS' : 'RECORDED_SOURCE_UNVERIFIED'),
                'observations' => $rows->toArray(), 'as_of' => now()->toISOString(),
            ])->header('Cache-Control', 'no-store, private');
        });
    }

    private function recordedInstant(mixed $value): string
    {
        abort_unless(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value), 422, 'Recorded meter history is unreadable.');
        try {
            $time = CarbonImmutable::parse($value, 'UTC');
            abort_unless($time->format('Y-m-d H:i:s') === str_replace('T', ' ', substr($value, 0, 19)), 422, 'Recorded meter history is unreadable.');

            return $time->toISOString();
        } catch (\Throwable) {
            abort(422, 'Recorded meter history is unreadable.');
        }
    }
}
