<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\ChargingActorConsent;
use App\Services\Charging\ConnectorTopology;
use App\Services\Charging\OperatingCalendar;
use App\Services\Charging\ReservationPolicy;
use App\Services\Charging\StationGate;
use App\Services\Finance\FinanceService;
use App\Services\Finance\PrechargePricing;
use App\Services\Finance\SessionCostProjection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SimulationController extends Controller
{
    /** Lock the current local simulator lineage before mutating any energy or status. */
    private function scopedLocalSession(Request $request, ChargingSession $snapshot, bool $meter): ChargingSession
    {
        $actor = app(ChargingActorConsent::class)->capture($request->user());
        $hint = ChargingSession::findOrFail($snapshot->id);
        $reservationHint = Reservation::findOrFail($hint->reservation_id);
        $station = Station::whereKey($reservationHint->station_id)->lockForUpdate()->firstOrFail();
        $reservation = Reservation::whereKey($reservationHint->id)->lockForUpdate()->firstOrFail();
        $session = ChargingSession::whereKey($hint->id)->lockForUpdate()->firstOrFail();
        abort_unless($session->reservation_id === $reservation->id && $reservation->station_id === $station->id, 409, 'Session target changed.');
        abort_unless(($actor->role === 'customer' && $actor->id === $reservation->user_id)
            || ($actor->role === 'vendor' && $actor->id === $station->vendor_id)
            || $actor->role === 'admin', 404);
        abort_if(SessionLink::where('charging_session_id', $session->id)->exists(), 409, 'CSMS-linked sessions require authenticated source events.');
        abort_unless($station->source === 'simulator' && $session->roaming_order_id === null, 409, 'Only local simulator sessions can be changed here.');
        if ($meter) {
            abort_unless($actor->role === 'vendor' && $actor->id === $station->vendor_id, 403);
        }

        return $session;
    }

    private function audit(Request $r, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    public function index(Request $r)
    {
        $q = ChargingSession::whereNull('roaming_order_id');
        if ($r->user()->role === 'vendor') {
            $q->whereIn('reservation_id', Reservation::whereIn('station_id', Station::where('vendor_id', $r->user()->id)->select('id'))->select('id'));
        } elseif ($r->user()->role !== 'admin') {
            $q->whereIn('reservation_id', Reservation::where('user_id', $r->user()->id)->select('id'));
        }

        $page = $q->orderByDesc('id')->paginate(25);
        $links = SessionLink::whereIn('charging_session_id', $page->getCollection()->pluck('id'))->get()->keyBy('charging_session_id');
        $commands = ChargerCommand::whereIn('id', $links->pluck('start_command_id')->merge($links->pluck('stop_command_id'))->filter())->get(['id', 'actor_id', 'consent_auth_epoch', 'status', 'failure_code'])->keyBy('id');
        $actors = User::whereIn('id', $commands->pluck('actor_id'))->get()->keyBy('id');

        $costs = app(SessionCostProjection::class)->forSessions($page->getCollection(), $links);

        return response()->json($page->through(function ($session) use ($links, $commands, $actors, $costs) {
            $link = $links->get($session->id);
            $start = $link ? $commands->get($link->start_command_id) : null;

            return $session->toArray() + ['cost_projection' => $costs->get($session->id), 'evidence' => $link ? 'SIMULATED_CSMS' : 'SIMULATED', 'payment_status' => 'not_processed', 'csms_linked' => $link !== null,
                'authorization_method' => $link?->rider_token_id ? 'rider_token' : ($link ? 'remote_start' : 'application_simulator'),
                'awaiting_rider_transaction' => $link?->rider_token_id !== null && $link->started_at === null && $link->ended_at === null && $link->closed_without_dispatch_at === null,
                'start_command_status' => $start?->status,
                'start_authorization_revoked' => $session->status === 'pending_start' && $start?->status === 'pending' && $link->transaction_id === null && $link->started_at === null && $link->closed_without_dispatch_at === null && $link->last_sequence === -1 && $link->last_meter_wh === null && ! app(ChargingActorConsent::class)->matches($actors->get($start->actor_id), $start->consent_auth_epoch),
                'start_admission_blocked' => in_array($start?->failure_code, ['pre_dispatch_actor_revoked', 'pre_dispatch_vendor_unavailable', 'pre_dispatch_company_gate', 'pre_dispatch_window_unavailable', 'pre_dispatch_operating_calendar'], true),
                'can_cancel_before_dispatch' => $link && $session->status === 'pending_start' && $start?->status === 'pending' && $link->transaction_id === null && $link->started_at === null && $link->closed_without_dispatch_at === null,
                'stop_command_status' => $link ? $commands->get($link->stop_command_id)?->status : null];
        }))->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $r, Reservation $reservation)
    {
        abort_unless($r->user()->id === $reservation->user_id, 404);

        return DB::transaction(function () use ($r, $reservation) {
            $actor = app(ChargingActorConsent::class)->capture($r->user());
            $station = Station::whereKey($reservation->station_id)->lockForUpdate()->firstOrFail();
            $b = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'customer' && $actor->id === $b->user_id, 404);
            abort_unless($b->station_id === $station->id && $station->source === 'simulator', 409, 'Reservation source changed.');
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
            app(OperatingCalendar::class)->requireStart($station);
            app(ReservationPolicy::class)->requireUnoccupied($station, $b->charging_evse_id);
            if ($b->connector_mapping_id) {
                abort_if(app(ConnectorTopology::class)->binding($b) !== null, 409, 'Use the mapped CSMS start.');
            }
            // Capture the test tariff once; future pricing changes cannot reprice this session.
            abort_if($b->charging_evse_id && ChargingSession::where('charging_evse_id', $b->charging_evse_id)->whereIn('status', ['pending_start', 'charging', 'stopping'])->exists(), 409, 'This EVSE still has an unresolved session.');
            $finance = app(FinanceService::class);
            $tariff = $finance->activeTariff($station);
            $priceConsentId = app(PrechargePricing::class)->consent($b, $station, $tariff);
            $s = ChargingSession::create(['reservation_id' => $b->id, 'charging_evse_id' => $b->charging_evse_id, 'rate_minor_per_kwh' => $tariff?->rate_minor_per_kwh ?? 100, 'currency' => $station->currency]);
            $finance->snapshotSession($s, $tariff, $priceConsentId);
            $this->audit($r, 'simulation.started', $s->id);

            return response()->json($s, 201);
        });
    }

    public function meter(Request $r, ChargingSession $session)
    {
        $v = $r->validate(['energy_wh' => 'required|integer|min:0|max:1000000']);

        return DB::transaction(function () use ($r, $session, $v) {
            $s = $this->scopedLocalSession($r, $session, true);
            abort_unless($s->status === 'charging', 409, 'Session is stopped.');
            abort_unless($v['energy_wh'] >= $s->energy_wh, 409, 'Cumulative meter cannot decrease.');
            $s->update($v);
            $this->audit($r, 'simulation.meter', $s->id);

            return $s;
        });
    }

    public function stop(Request $r, ChargingSession $session)
    {
        return DB::transaction(function () use ($r, $session) {
            $s = $this->scopedLocalSession($r, $session, false);
            if ($s->status === 'stopped') {
                return $s;
            }
            $s->update(['status' => 'stopped', 'stopped_at' => now(), 'total_minor' => intdiv($s->energy_wh * $s->rate_minor_per_kwh + 500, 1000)]);
            $this->audit($r, 'simulation.stopped', $s->id);

            return $s;
        });
    }
}
