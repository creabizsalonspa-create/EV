<?php

namespace App\Services\Charging;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SessionBridge
{
    public function enabled(): void
    {
        abort_unless(config('csms.session_bridge_enabled') && ! app()->environment('production'), 503, 'The local simulator session bridge is disabled.');
    }

    private function audit(int $actor, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    private function command(int $actor, int $station, string $action, array $payload, string $key): ChargerCommand
    {
        return ChargerCommand::create(['actor_id' => $actor, 'station_id' => $station, 'action' => $action, 'payload' => $payload, 'idempotency_key' => $key, 'payload_hash' => hash('sha256', json_encode([$station, $action, $payload], JSON_THROW_ON_ERROR))]);
    }

    public function start(Reservation $reservation, int $actor): array
    {
        $this->enabled();
        abort_unless($reservation->user_id === $actor, 404);

        return DB::transaction(function () use ($reservation, $actor) {
            $binding = DB::table('csms_bindings')->where('station_id', $reservation->station_id)->lockForUpdate()->first();
            abort_unless($binding, 409, 'No CSMS simulator binding.');
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $old = ChargingSession::where('reservation_id', $r->id)->first();
            if ($old) {
                $link = SessionLink::where('charging_session_id', $old->id)->first();
                abort_unless($link, 409, 'This reservation already uses the independent application simulator.');

                return $this->summary($link);
            }
            $start = CarbonImmutable::parse($r->starts_at, 'UTC');
            abort_unless($r->status === 'confirmed' && now()->gte($start) && now()->lt($start->addMinutes(30)), 409, 'Outside the confirmed reservation window.');
            $station = Station::findOrFail($r->station_id);
            abort_unless($station->source === 'simulator' && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/', $binding->identifier), 409, 'Only provisioned simulated chargers are enabled.');
            abort_if(SessionLink::where('binding_id', $binding->id)->whereNull('ended_at')->exists(), 409, 'An unresolved charging session already owns this simulator.');
            $finance = app(FinanceService::class);
            $tariff = $finance->activeTariff($station);
            $s = ChargingSession::create(['reservation_id' => $r->id, 'status' => 'pending_start', 'rate_minor_per_kwh' => $tariff?->rate_minor_per_kwh ?? 100, 'currency' => $station->currency]);
            $finance->snapshotSession($s, $tariff);
            $c = $this->command($actor, $station->id, 'start', ['session_id' => $s->id, 'id_token' => 'EV-SIM-'.$s->id], 'csms-session:'.$s->id.':start');
            $link = SessionLink::create(['charging_session_id' => $s->id, 'binding_id' => $binding->id, 'start_command_id' => $c->id, 'protocol' => $binding->protocol, 'identifier' => $binding->identifier, 'last_sequence' => -1]);
            $this->audit($actor, 'csms.session_requested', $s->id);

            return $this->summary($link);
        }, 3);
    }

    public function stop(ChargingSession $session, int $actor, string $role): array
    {
        $this->enabled();
        $r = Reservation::findOrFail($session->reservation_id);
        $station = Station::findOrFail($r->station_id);
        abort_unless($actor === $r->user_id || ($role === 'vendor' && $actor === $station->vendor_id) || $role === 'admin', 404);

        return DB::transaction(function () use ($session, $actor, $station) {
            $link = SessionLink::where('charging_session_id', $session->id)->lockForUpdate()->firstOrFail();
            $s = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($link->stop_command_id || $s->status === 'stopped') {
                return $this->summary($link);
            }
            abort_unless($s->status === 'charging' && $link->transaction_id !== null, 409, 'Wait for the authoritative simulator transaction before stopping.');
            $c = $this->command($actor, $station->id, 'stop', ['session_id' => $s->id, 'transaction_id' => $link->transaction_id], 'csms-session:'.$s->id.':stop');
            $link->update(['stop_command_id' => $c->id]);
            $s->update(['status' => 'stopping']);
            $this->audit($actor, 'csms.stop_requested', $s->id);

            return $this->summary($link);
        }, 3);
    }

    public function summary(SessionLink $link): array
    {
        return ['session' => ChargingSession::findOrFail($link->charging_session_id), 'link' => ['id' => $link->id, 'protocol' => $link->protocol, 'transaction_id' => $link->transaction_id, 'last_sequence' => $link->last_sequence, 'started_at' => $link->started_at, 'ended_at' => $link->ended_at],
            'start_command' => ChargerCommand::whereKey($link->start_command_id)->first(['id', 'status', 'failure_code']), 'stop_command' => $link->stop_command_id ? ChargerCommand::whereKey($link->stop_command_id)->first(['id', 'status', 'failure_code']) : null, 'evidence' => 'SIMULATED_CSMS', 'physical_charging_verified' => false];
    }

    public function ingest(array $v): array
    {
        $this->enabled();
        $at = CarbonImmutable::parse($v['occurred_at'])->utc();
        abort_if($v['protocol'] === '1.6' && (! ctype_digit($v['transaction_id']) || (int) $v['transaction_id'] > 2147483647), 422, 'Invalid OCPP 1.6 transaction identifier.');
        abort_if($at->gt(now()->addMinutes(5)), 422, 'Source event time is in the future.');
        $hash = hash('sha256', json_encode([(int) $v['session_id'], $v['identifier'], $v['protocol'], $v['transaction_id'], $v['type'], (int) $v['sequence'], (int) $v['meter_wh'], $at->format('Y-m-d\TH:i:s.u\Z')], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($v, $at, $hash) {
            $link = SessionLink::where('charging_session_id', $v['session_id'])->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($link->identifier, $v['identifier']) && $link->protocol === $v['protocol'], 409, 'CSMS binding mismatch.');
            $old = DB::table('csms_inbox_events')->where('event_id', $v['event_id'])->first();
            if ($old) {
                abort_unless($old->link_id === $link->id && hash_equals($old->fingerprint, $hash), 409, 'Event identity payload conflict.');

                return ['outcome' => 'duplicate', 'session_id' => $link->charging_session_id];
            }
            $sequence = (int) $v['sequence'];
            $meter = (int) $v['meter_wh'];
            $prior = DB::table('csms_inbox_events')->where('link_id', $link->id)->where('sequence', $sequence)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->fingerprint, $hash), 409, 'Sequence payload conflict.');

                return ['outcome' => 'duplicate', 'session_id' => $link->charging_session_id];
            }
            abort_unless($link->transaction_id === null || $link->transaction_id === $v['transaction_id'], 409, 'Transaction identity conflict.');
            $s = ChargingSession::whereKey($link->charging_session_id)->lockForUpdate()->firstOrFail();
            $r = Reservation::findOrFail($s->reservation_id);
            if ($sequence <= $link->last_sequence) {
                $outcome = 'stale';
            } else {
                abort_unless($link->ended_at === null, 409, 'The reconciled transaction is immutable.');
                abort_if($link->last_event_at && $at->lt($link->last_event_at), 409, 'Source event time moved backwards.');
                if ($v['type'] === 'started') {
                    abort_unless($s->status === 'pending_start' && $link->transaction_id === null && $sequence === 0, 409, 'Transaction already started.');
                    abort_if($at->lt($link->created_at->subSeconds(2)), 409, 'Event predates the session request.');
                    $link->transaction_id = $v['transaction_id'];
                    $link->meter_start_wh = $meter;
                    $link->started_at = $at;
                    $s->status = 'charging';
                } else {
                    abort_unless($link->transaction_id !== null && $link->started_at !== null, 409, 'A started transaction is required.');
                    abort_if($at->lt($link->started_at), 409, 'Meter event predates transaction start.');
                    abort_unless($meter >= $link->last_meter_wh, 409, 'Cumulative meter decreased; reconciliation required.');
                    $delta = $meter - $link->meter_start_wh;
                    abort_unless($delta <= 1000000, 409, 'Energy exceeds the simulator safety bound.');
                    $s->energy_wh = $delta;
                    if ($v['type'] === 'ended') {
                        $link->ended_at = $at;
                        $s->status = 'stopped';
                        $s->stopped_at = $at;
                        $s->total_minor = Money::multiplyDivide($delta, (int) $s->rate_minor_per_kwh, 1000);
                    }
                }
                $link->last_meter_wh = $meter;
                $link->last_sequence = $sequence;
                $link->last_event_at = $at;
                $link->save();
                $s->save();
                $outcome = 'applied';
                if ($v['type'] === 'ended') {
                    app(FinanceService::class)->issue($s, $r->user_id);
                }
                $this->audit($r->user_id, 'csms.event.'.$v['type'], $s->id);
            }
            DB::table('csms_inbox_events')->insert(['link_id' => $link->id, 'event_id' => $v['event_id'], 'fingerprint' => $hash, 'type' => $v['type'], 'sequence' => $sequence, 'meter_wh' => $meter, 'occurred_at' => $at, 'outcome' => $outcome, 'created_at' => now()]);

            return ['outcome' => $outcome, 'session_id' => $s->id, 'status' => $s->status, 'energy_wh' => $s->energy_wh];
        }, 3);
    }
}
