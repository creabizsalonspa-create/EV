<?php

namespace App\Http\Controllers;

use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SessionBridge;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

class CsmsSessionController extends Controller
{
    public function start(Request $r, Reservation $reservation, SessionBridge $bridge)
    {
        return response()->json($bridge->start($reservation, $r->user()->id), 202);
    }

    public function stop(Request $r, ChargingSession $session, SessionBridge $bridge)
    {
        return response()->json($bridge->stop($session, $r->user()->id, $r->user()->role), 202);
    }

    public function reconcile(Request $r, ChargingSession $session, SessionBridge $bridge, CommandReconciler $reconciler)
    {
        $reservation = Reservation::findOrFail($session->reservation_id);
        $station = Station::findOrFail($reservation->station_id);
        abort_unless($r->user()->id === $reservation->user_id || ($r->user()->role === 'vendor' && $r->user()->id === $station->vendor_id) || $r->user()->role === 'admin', 404);
        $bridge->enabled();
        $link = SessionLink::where('charging_session_id', $session->id)->firstOrFail();
        $count = $reconciler->forSession($session->id);

        return response()->json(['reconciled' => $count, 'source' => 'authenticated_applied_inbox_only', 'network_requests' => 0, 'command_acceptance_verified' => false, 'summary' => $bridge->summary($link->refresh())]);
    }

    public function event(Request $r, SessionBridge $bridge)
    {
        $bridge->enabled();
        $key = (string) config('csms.event_secret');
        abort_unless(strlen($key) >= 32, 503, 'Event authentication is not configured.');
        $ts = $r->header('X-EV-Timestamp', '');
        $signature = $r->header('X-EV-Signature', '');
        abort_unless(ctype_digit($ts) && abs(time() - (int) $ts) <= 300 && strlen($signature) === 64 && hash_equals(hash_hmac('sha256', $ts.'.'.$r->getContent(), $key), $signature), 401, 'Invalid CSMS event authentication.');
        abort_if(strlen($r->getContent()) > 16384, 413);
        $v = $r->validate(['event_id' => 'required|string|max:100', 'session_id' => 'required|integer', 'identifier' => ['required', 'string', 'regex:/^ev-test-[a-zA-Z0-9-]{1,80}$/'], 'protocol' => 'required|in:1.6,2.0.1', 'transaction_id' => 'required|string|max:36', 'type' => 'required|in:started,meter,ended', 'sequence' => 'required|integer|between:0,1000000000', 'meter_wh' => 'required|integer|between:0,9000000000000', 'occurred_at' => 'required|date', 'source_evse_number' => 'sometimes|integer|between:0,1000', 'source_connector_number' => 'sometimes|integer|between:1,100']);
        try {
            return response()->json($bridge->ingest($v));
        } catch (UniqueConstraintViolationException $e) {
            abort(409, 'Event identity or transaction is already claimed; reconcile before retrying.');
        }
    }
}
