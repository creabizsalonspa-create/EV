<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SimulationController extends Controller
{
    private function check(Request $r, ChargingSession $s): Reservation
    {
        $b = Reservation::findOrFail($s->reservation_id);
        $station = Station::findOrFail($b->station_id);
        abort_unless($r->user()->id === $b->user_id || ($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id) || $r->user()->role === 'admin', 404);

        return $b;
    }

    private function audit(Request $r, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    public function index(Request $r)
    {
        $q = ChargingSession::query();
        if ($r->user()->role === 'vendor') {
            $q->whereIn('reservation_id', Reservation::whereIn('station_id', Station::where('vendor_id', $r->user()->id)->select('id'))->select('id'));
        } elseif ($r->user()->role !== 'admin') {
            $q->whereIn('reservation_id', Reservation::where('user_id', $r->user()->id)->select('id'));
        }

        return $q->orderByDesc('id')->paginate(25)->through(fn ($s) => $s->toArray() + ['evidence' => 'SIMULATED', 'payment_status' => 'not_processed']);
    }

    public function start(Request $r, Reservation $reservation)
    {
        abort_unless($r->user()->id === $reservation->user_id, 404);

        return DB::transaction(function () use ($r, $reservation) {
            $b = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $old = ChargingSession::where('reservation_id', $b->id)->first();
            if ($old) {
                return response()->json($old);
            }
            abort_unless($b->status === 'confirmed', 409, 'Reservation is not confirmed.');
            $start = CarbonImmutable::parse($b->starts_at, 'UTC');
            abort_unless(now()->gte($start) && now()->lt($start->addMinutes(30)), 409, 'Outside reserved slot.');
            $station = Station::findOrFail($b->station_id);
            abort_unless($station->source === 'simulator', 409);
            // This fixed tariff is a simulation fixture, never a commercial tariff or real invoice.
            $s = ChargingSession::create(['reservation_id' => $b->id, 'rate_minor_per_kwh' => 100, 'currency' => $station->currency]);
            $this->audit($r, 'simulation.started', $s->id);

            return response()->json($s, 201);
        });
    }

    public function meter(Request $r, ChargingSession $session)
    {
        $b = $this->check($r, $session);
        $station = Station::findOrFail($b->station_id);
        abort_unless($r->user()->role === 'vendor' && $station->vendor_id === $r->user()->id, 403);
        $v = $r->validate(['energy_wh' => 'required|integer|min:0|max:1000000']);

        return DB::transaction(function () use ($r, $session, $v) {
            $s = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->status === 'charging', 409, 'Session is stopped.');
            abort_unless($v['energy_wh'] >= $s->energy_wh, 409, 'Cumulative meter cannot decrease.');
            $s->update($v);
            $this->audit($r, 'simulation.meter', $s->id);

            return $s;
        });
    }

    public function stop(Request $r, ChargingSession $session)
    {
        $this->check($r, $session);

        return DB::transaction(function () use ($r, $session) {
            $s = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($s->status === 'stopped') {
                return $s;
            }
            $s->update(['status' => 'stopped', 'stopped_at' => now(), 'total_minor' => intdiv($s->energy_wh * $s->rate_minor_per_kwh + 500, 1000)]);
            $this->audit($r, 'simulation.stopped', $s->id);

            return $s;
        });
    }
}
