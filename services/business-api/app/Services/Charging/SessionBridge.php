<?php

namespace App\Services\Charging;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
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
            Station::whereKey($reservation->station_id)->lockForUpdate()->firstOrFail();
            $binding = app(ConnectorTopology::class)->binding($reservation, true);
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
            app(StationGate::class)->requireReady($station, $r->connector_mapping_id);
            abort_if(SessionLink::where('binding_id', $binding->id)->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->when($r->connector_mapping_id, fn ($q) => $q->where(function ($q) use ($r) {
                $q->whereNull('connector_mapping_id')->orWhereIn('connector_mapping_id', ConnectorMapping::where('evse_id', $r->charging_evse_id)->select('id'));
            }))->exists(), 409, 'An unresolved charging session already owns this simulator.');
            abort_if($r->charging_evse_id && ChargingSession::where('charging_evse_id', $r->charging_evse_id)->whereIn('status', ['pending_start', 'charging', 'stopping'])->exists(), 409, 'This EVSE still has an unresolved session.');
            $finance = app(FinanceService::class);
            $tariff = $finance->activeTariff($station);
            $s = ChargingSession::create(['reservation_id' => $r->id, 'charging_evse_id' => $r->charging_evse_id, 'status' => 'pending_start', 'rate_minor_per_kwh' => $tariff?->rate_minor_per_kwh ?? 100, 'currency' => $station->currency]);
            $finance->snapshotSession($s, $tariff);
            $c = $this->command($actor, $station->id, 'start', ['session_id' => $s->id, 'id_token' => 'EV-SIM-'.$s->id], 'csms-session:'.$s->id.':start');
            $link = SessionLink::create(['charging_session_id' => $s->id, 'binding_id' => $binding->id, 'start_command_id' => $c->id, 'connector_mapping_id' => $r->connector_mapping_id, 'protocol' => $binding->protocol, 'identifier' => $binding->identifier, 'last_sequence' => -1]);
            $this->audit($actor, 'csms.session_requested', $s->id);

            return $this->summary($link);
        }, 3);
    }

    /** Caller holds the authorized reservation lock; never cancel an admitted or uncertain dispatch. */
    public function cancelUndispatched(ChargingSession $session, Reservation $reservation): void
    {
        $this->closeUndispatched($session, $reservation, 'customer_cancelled_before_dispatch', 'cancelled');
    }

    public function expireUndispatched(ChargingSession $session, Reservation $reservation): void
    {
        $this->enabled();
        $start = CarbonImmutable::parse($reservation->starts_at, 'UTC');
        abort_unless($reservation->status === 'confirmed' && now()->gte($start->addMinutes(30)), 409, 'Reservation window has not expired.');
        $this->closeUndispatched($session, $reservation, 'reservation_expired_before_dispatch', 'expired_unstarted');
    }

    private function closeUndispatched(ChargingSession $session, Reservation $reservation, string $reason, string $status): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Cancellation requires a transaction.');
        }
        $candidate = SessionLink::where('charging_session_id', $session->id)->first();
        abort_unless($candidate, 409, 'A started reservation cannot be cancelled.');
        $command = ChargerCommand::whereKey($candidate->start_command_id)->lockForUpdate()->firstOrFail();
        $link = SessionLink::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        $session = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        abort_unless($link->start_command_id === $command->id && $command->status === 'pending' && $command->action === 'start' && $command->station_id === $reservation->station_id && (int) ($command->payload['session_id'] ?? 0) === $session->id && $session->reservation_id === $reservation->id && $session->status === 'pending_start' && $session->stopped_at === null && $session->total_minor === null && (int) $session->energy_wh === 0 && $link->transaction_id === null && $link->started_at === null && $link->ended_at === null && $link->last_sequence === -1 && $link->last_meter_wh === null && $link->stop_command_id === null && $link->closed_without_dispatch_at === null, 409, 'An admitted or uncertain charging start requires reconciliation; cancellation is unavailable.');
        abort_if(DB::table('csms_inbox_events')->where('link_id', $link->id)->exists(), 409, 'Observed source activity requires reconciliation.');
        $command->update(['status' => 'cancelled', 'failure_code' => $status === 'cancelled' ? 'cancelled_before_dispatch' : 'expired_before_dispatch']);
        $session->update(['status' => $status]);
        $link->update(['closed_without_dispatch_at' => now(), 'closure_reason' => $reason]);
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
        return ['session' => ChargingSession::findOrFail($link->charging_session_id), 'link' => ['id' => $link->id, 'protocol' => $link->protocol, 'transaction_id' => $link->transaction_id, 'last_sequence' => $link->last_sequence, 'started_at' => $link->started_at, 'ended_at' => $link->ended_at, 'closed_without_dispatch_at' => $link->closed_without_dispatch_at, 'closure_reason' => $link->closure_reason],
            'start_command' => ChargerCommand::whereKey($link->start_command_id)->first(['id', 'status', 'failure_code']), 'stop_command' => $link->stop_command_id ? ChargerCommand::whereKey($link->stop_command_id)->first(['id', 'status', 'failure_code']) : null, 'evidence' => 'SIMULATED_CSMS', 'physical_charging_verified' => false];
    }

    public function ingest(array $v): array
    {
        $this->enabled();
        $at = CarbonImmutable::parse($v['occurred_at'])->utc();
        abort_if($v['protocol'] === '1.6' && (! ctype_digit($v['transaction_id']) || (int) $v['transaction_id'] > 2147483647), 422, 'Invalid OCPP 1.6 transaction identifier.');
        abort_if($at->gt(now()->addMinutes(5)), 422, 'Source event time is in the future.');
        $identity = [(int) $v['session_id'], $v['identifier'], $v['protocol'], $v['transaction_id'], $v['type'], (int) $v['sequence'], (int) $v['meter_wh'], $at->format('Y-m-d\TH:i:s.u\Z')];
        if (array_key_exists('source_evse_number', $v) || array_key_exists('source_connector_number', $v)) {
            $identity[] = $v['source_evse_number'] ?? null;
            $identity[] = $v['source_connector_number'] ?? null;
        }
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($v, $at, $hash) {
            $link = SessionLink::where('charging_session_id', $v['session_id'])->lockForUpdate()->firstOrFail();
            if ($link->connector_mapping_id) {
                $map = ConnectorMapping::findOrFail($link->connector_mapping_id);
                $evse = Evse::findOrFail($map->evse_id);
                abort_unless(($v['source_connector_number'] ?? null) === $map->source_connector_number && ($v['source_evse_number'] ?? null) === $evse->source_evse_number && $evse->binding_id === $link->binding_id, 409, 'Canonical source target mismatch.');
            }
            abort_unless($link->closed_without_dispatch_at === null, 409, 'This request was closed before dispatch; unexpected source activity requires reconciliation.');
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
