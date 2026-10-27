<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\Reservation;
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
