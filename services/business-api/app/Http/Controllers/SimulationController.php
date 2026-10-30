<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\Charging\ConnectorTopology;
use App\Services\Charging\StationGate;
use App\Services\Finance\FinanceService;
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

        $page = $q->orderByDesc('id')->paginate(25);
        $links = SessionLink::whereIn('charging_session_id', $page->getCollection()->pluck('id'))->get()->keyBy('charging_session_id');
        $commands = ChargerCommand::whereIn('id', $links->pluck('start_command_id')->merge($links->pluck('stop_command_id'))->filter())->get(['id', 'status', 'failure_code'])->keyBy('id');

        return $page->through(function ($session) use ($links, $commands) {
            $link = $links->get($session->id);
            $start = $link ? $commands->get($link->start_command_id) : null;

            return $session->toArray() + ['evidence' => $link ? 'SIMULATED_CSMS' : 'SIMULATED', 'payment_status' => 'not_processed', 'csms_linked' => $link !== null,
                'start_command_status' => $start?->status,
                'start_admission_blocked' => in_array($start?->failure_code, ['pre_dispatch_company_gate', 'pre_dispatch_window_unavailable'], true),
                'can_cancel_before_dispatch' => $link && $session->status === 'pending_start' && $start?->status === 'pending' && $link->transaction_id === null && $link->started_at === null && $link->closed_without_dispatch_at === null,
                'stop_command_status' => $link ? $commands->get($link->stop_command_id)?->status : null];
        });
    }

    public function start(Request $r, Reservation $reservation)
    {
        abort_unless($r->user()->id === $reservation->user_id, 404);

        return DB::transaction(function () use ($r, $reservation) {
            Station::whereKey($reservation->station_id)->lockForUpdate()->firstOrFail();
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
            app(StationGate::class)->requireReady($station, $b->connector_mapping_id);
            if ($b->connector_mapping_id) {
                abort_if(app(ConnectorTopology::class)->binding($b) !== null, 409, 'Use the mapped CSMS start.');
            }
            // Capture the test tariff once; future pricing changes cannot reprice this session.
            abort_if($b->charging_evse_id && ChargingSession::where('charging_evse_id', $b->charging_evse_id)->whereIn('status', ['pending_start', 'charging', 'stopping'])->exists(), 409, 'This EVSE still has an unresolved session.');
            $finance = app(FinanceService::class);
            $tariff = $finance->activeTariff($station);
            $s = ChargingSession::create(['reservation_id' => $b->id, 'charging_evse_id' => $b->charging_evse_id, 'rate_minor_per_kwh' => $tariff?->rate_minor_per_kwh ?? 100, 'currency' => $station->currency]);
            $finance->snapshotSession($s, $tariff);
            $this->audit($r, 'simulation.started', $s->id);

            return response()->json($s, 201);
        });
    }

    public function meter(Request $r, ChargingSession $session)
    {
        $b = $this->check($r, $session);
        abort_if(SessionLink::where('charging_session_id', $session->id)->exists(), 409, 'A CSMS-linked session accepts only authenticated source meter events.');
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
        abort_if(SessionLink::where('charging_session_id', $session->id)->exists(), 409, 'Use the CSMS stop command; completion requires the source final meter.');

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
