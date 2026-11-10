<?php

namespace App\Services\Charging;

use App\Models\Charging\RiderAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Private read models only; never authorizes a card, command or invoice. */
class RiderAccessHistory
{
    public function response(Request $request, RiderAccessToken $token, ?int $grant = null)
    {
        $v = $request->validate(['kind' => 'sometimes|in:events,sessions', 'page' => 'sometimes|integer|min:1|max:100000']);
        $kind = $v['kind'] ?? 'events';
        if ($kind === 'events') {
            // Operator views see only their grant events, never another CPO's
            // reviews, global publication events, actor identities or notes.
            $rows = DB::table('rider_authorization_events')->where('token_id', $token->id)
                ->when($grant !== null, fn ($q) => $q->where('grant_id', $grant))
                ->select('id', 'action', 'created_at')->orderByDesc('id')->paginate(25);
        } else {
            $rows = DB::table('csms_session_links as l')
                ->join('charging_sessions as c', 'c.id', '=', 'l.charging_session_id')
                ->join('reservations as r', 'r.id', '=', 'c.reservation_id')
                ->join('rider_charging_grants as g', 'g.id', '=', 'l.rider_grant_id')
                ->join('csms_bindings as b', 'b.id', '=', 'l.binding_id')
                ->join('stations as s', 's.id', '=', 'r.station_id')
                ->where('l.rider_token_id', $token->id)->where('g.token_id', $token->id)
                ->where('r.user_id', $token->user_id)->whereColumn('r.station_id', 'g.station_id')
                ->whereColumn('b.station_id', 'r.station_id')->whereColumn('b.protocol', 'l.protocol')->where('s.source', 'simulator')
                ->when($grant !== null, fn ($q) => $q->where('g.id', $grant))
                ->select('l.id', 'l.charging_session_id', 'l.protocol', 'l.created_at', 'l.started_at', 'l.ended_at', 'l.closed_without_dispatch_at', 'l.transaction_id')
                ->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM csms_inbox_events e WHERE e.link_id = l.id AND e.type = 'started' AND e.outcome = 'applied') THEN 1 ELSE 0 END AS applied_start")
                ->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM csms_inbox_events e WHERE e.link_id = l.id AND e.type = 'ended' AND e.outcome = 'applied') THEN 1 ELSE 0 END AS applied_end")
                ->orderByDesc('l.id')->paginate(25);
            $rows->through(function ($row) {
                $start = $row->transaction_id !== null && $row->started_at !== null && (bool) $row->applied_start;
                $end = $start && $row->ended_at !== null && (bool) $row->applied_end;
                $state = match (true) {
                    $row->closed_without_dispatch_at !== null && ! $start && $row->transaction_id === null && $row->ended_at === null => 'closed_without_dispatch',
                    $row->closed_without_dispatch_at !== null => 'requires_review',
                    $end => 'source_ended',
                    $start && $row->ended_at === null => 'source_active',
                    $row->started_at === null && $row->ended_at === null && $row->transaction_id === null => 'awaiting_source',
                    default => 'requires_review',
                };

                return ['id' => $row->id, 'charging_session_id' => $row->charging_session_id, 'protocol' => $row->protocol,
                    'created_at' => $row->created_at, 'started_at' => $row->started_at, 'ended_at' => $row->ended_at,
                    'state' => $state, 'source_start_observed' => $start, 'source_end_observed' => $end];
            });
        }

        return response()->json($rows->toArray() + ['kind' => $kind, 'read_only' => true, 'evidence' => 'SIMULATED_SOURCE', 'hardware_verified' => false, 'charging_authorized' => false])
            ->header('Cache-Control', 'private, no-store');
    }
}
