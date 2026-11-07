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
        // These are historical records owned by the station's current
        // vendor, not live charger states or physical fault verification.
        $monitorReviewCount = DB::table('charging_fault_cursors')
            ->whereIn('binding_id', clone $bindingIds)
            ->where('state', 'requires_review')->count();
        $faults = DB::table('charging_fault_alerts as f')
            ->join('charging_status_observations as o', 'o.id', '=', 'f.observation_id')
            ->whereIn('o.binding_id', clone $bindingIds)
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
