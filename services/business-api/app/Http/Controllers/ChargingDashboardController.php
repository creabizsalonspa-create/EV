<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only business evidence, never an inference of physical charger health. */
class ChargingDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['vendor', 'admin'], true), 403);
        $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $stations = Station::query()->when($request->user()->role !== 'admin', fn ($q) => $q->where('vendor_id', $request->user()->id))->select('id');
        $commands = ChargerCommand::whereIn('station_id', clone $stations);
        $bindingIds = DB::table('csms_bindings')->whereIn('station_id', clone $stations)->select('id');
        $links = SessionLink::whereIn('binding_id', clone $bindingIds);
        $active = (clone $links)->whereNull('ended_at')->whereNull('closed_without_dispatch_at');
        $cutoff = now()->subMinutes(5);
        $missing = static fn ($q) => $q->whereNull('last_event_at')->orWhere('last_event_at', '<', $cutoff);
        $counts = (clone $commands)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count) => (int) $count);
        $cases = DB::table('charging_operational_cases')->whereIn('command_id', (clone $commands)->select('id'))->where('state', '!=', 'resolved')->count();
        // Source fault metrics are scoped to the *currently valid* simulator
        // company/site/charger ownership mapping. A station reassignment must
        // not expose the previous vendor's archived fault workload to its new
        // owner, even as aggregate counts.
        $faultBindingIds = DB::table('csms_bindings as b')
            ->join('stations as st', 'st.id', '=', 'b.station_id')
            ->join('vendor_chargers as vc', 'vc.id', '=', 'b.vendor_charger_id')
            ->join('vendor_sites as vs', 'vs.id', '=', 'vc.site_id')
            ->join('vendor_companies as co', 'co.id', '=', 'vc.company_id')
            ->whereColumn('vs.company_id', 'co.id')
            ->whereColumn('vs.station_id', 'st.id')
            ->whereColumn('st.vendor_id', 'co.owner_id')
            ->whereColumn('vc.ocpp_protocol', 'b.protocol')
            ->where('vc.source', 'simulator')
            ->where('st.source', 'simulator')
            ->when($request->user()->role !== 'admin',
                fn ($q) => $q->where('co.owner_id', $request->user()->id))
            ->select('b.id');
        // These are historical operator-attention records, not live physical
        // charger health, connectivity, or verified fault repair.
        $monitorReviewCount = DB::table('charging_fault_cursors')
            ->whereIn('binding_id', clone $faultBindingIds)
            ->where('state', 'requires_review')->count();
        $faults = DB::table('charging_fault_alerts as f')
            ->join('charging_status_observations as o', 'o.id', '=', 'f.observation_id')
            ->whereIn('o.binding_id', clone $faultBindingIds)
            ->whereNull('f.acknowledged_at');
        $faultAttentionCount = (clone $faults)->count();
        $faultRecoveredCount = (clone $faults)->whereNotNull('f.recovery_observation_id')->count();
        // Project only public-to-operator fields: no payloads, tokens, notes or customer identity.
        $attention = (clone $active)->where($missing)->orderBy('id')->paginate(25, ['id', 'charging_session_id', 'protocol', 'started_at', 'last_event_at', 'created_at'])->through(fn ($link) => [
            'session_id' => $link->charging_session_id,
            'protocol' => $link->protocol,
            'started_at' => $link->started_at,
            'last_event_at' => $link->last_event_at,
            'evidence_state' => $link->last_event_at === null ? 'not_observed' : 'stale',
        ]);

        return response()->json([
            'observed_at' => now()->toISOString(),
            'stale_after_seconds' => 300,
            'station_count' => (clone $stations)->count(),
            'command_counts' => (object) $counts->all(),
            'active_session_count' => (clone $active)->count(),
            'missing_recent_evidence_count' => (clone $active)->where($missing)->count(),
            'unresolved_case_count' => $cases,
            'recorded_monitor_review_count' => $monitorReviewCount,
            'unacknowledged_fault_alert_count' => $faultAttentionCount,
            'source_recovered_unacknowledged_fault_count' => $faultRecoveredCount,
            'alert_metrics_are_historical' => true,
            'attention' => $attention,
            'physical_charging_verified' => false,
        ])->header('Cache-Control', 'private, no-store');
    }
}
