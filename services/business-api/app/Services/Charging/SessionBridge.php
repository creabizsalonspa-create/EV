<?php

namespace App\Services\Charging;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\RiderAccessToken;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use App\Services\Finance\PrechargePricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

    private function command(int $actor, int $station, string $action, array $payload, string $key, ?int $epoch = null, ?array $staffStop = null): ChargerCommand
    {
        return ChargerCommand::create(['consent_auth_epoch' => $action === 'start' || $staffStop !== null ? $epoch : null, 'staff_stop_authority' => $staffStop, 'actor_id' => $actor, 'station_id' => $station, 'action' => $action, 'payload' => $payload, 'idempotency_key' => $key, 'payload_hash' => hash('sha256', json_encode([$station, $action, $payload], JSON_THROW_ON_ERROR))]);
    }

    public function start(Reservation $reservation, int $actor, ?RiderAccessToken $rider = null, ?int $grantId = null, ?User $consentingActor = null): array
    {
        $this->enabled();
        abort_unless($reservation->user_id === $actor, 404);

        return DB::transaction(function () use ($reservation, $actor, $rider, $grantId, $consentingActor) {
            $customer = $consentingActor
                ? app(ChargingActorConsent::class)->capture($consentingActor)
                : User::whereKey($actor)->lockForUpdate()->firstOrFail();
            abort_unless($customer->id === $actor && $customer->role === 'customer' && $customer->account_status === 'active', 403);
            $station = Station::whereKey($reservation->station_id)->lockForUpdate()->firstOrFail();
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->user_id === $actor, 404);
            abort_unless($r->station_id === $station->id
                && $r->connector_mapping_id === $reservation->connector_mapping_id
                && $r->charging_evse_id === $reservation->charging_evse_id, 409,
                'Reservation target changed; refresh before starting.');
            $binding = app(ConnectorTopology::class)->binding($r, true);
            abort_unless($binding, 409, 'No CSMS simulator binding.');
            if ($rider) {
                app(StationGate::class)->requireReady(Station::findOrFail($r->station_id), $r->connector_mapping_id);
                $rider = app(RiderAuthority::class)->requireGrant($r, $rider->id, $grantId ?? 0);
            }
            $old = ChargingSession::where('reservation_id', $r->id)->first();
            if ($old) {
                $link = SessionLink::where('charging_session_id', $old->id)->first();
                abort_unless($link, 409, 'This reservation already uses the independent application simulator.');
                abort_unless($link->binding_id === $binding->id
                    && $link->identifier === $binding->identifier && $link->protocol === $binding->protocol
                    && $link->connector_mapping_id === $r->connector_mapping_id
                    && $old->charging_evse_id === $r->charging_evse_id, 409,
                    'Existing charging source requires reconciliation.');
                if ($rider) {
                    if ($old->status === 'pending_start') {
                        app(OperatingCalendar::class)->requireStart(Station::findOrFail($r->station_id));
                    }
                    abort_unless($link->rider_token_id === $rider->id && $link->rider_grant_id === $grantId && $link->closed_without_dispatch_at === null && $link->ended_at === null, 409, 'Reservation uses a different or terminal charging authorization.');
                }

                return $this->summary($link);
            }
            $start = CarbonImmutable::parse($r->starts_at, 'UTC');
            abort_unless($r->status === 'confirmed' && now()->gte($start) && now()->lt($start->addMinutes(30)), 409, 'Outside the confirmed reservation window.');
            $station = Station::findOrFail($r->station_id);
            abort_unless($station->source === 'simulator' && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/', $binding->identifier), 409, 'Only provisioned simulated chargers are enabled.');
            app(StationGate::class)->requireReady($station, $r->connector_mapping_id);
            app(OperatingCalendar::class)->requireStart($station);
            app(ReservationPolicy::class)->requireUnoccupied($station, $r->charging_evse_id);
            abort_if(SessionLink::where('binding_id', $binding->id)->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->when($r->connector_mapping_id, fn ($q) => $q->where(function ($q) use ($r) {
                $q->whereNull('connector_mapping_id')->orWhereIn('connector_mapping_id', ConnectorMapping::where('evse_id', $r->charging_evse_id)->select('id'));
            }))->exists(), 409, 'An unresolved charging session already owns this simulator.');
            abort_if($r->charging_evse_id && ChargingSession::where('charging_evse_id', $r->charging_evse_id)->whereIn('status', ['pending_start', 'charging', 'stopping'])->exists(), 409, 'This EVSE still has an unresolved session.');
            $finance = app(FinanceService::class);
            $tariff = $finance->activeTariff($station);
            $priceConsentId = app(PrechargePricing::class)->consent($r, $station, $tariff);
            $s = ChargingSession::create(['reservation_id' => $r->id, 'charging_evse_id' => $r->charging_evse_id, 'status' => 'pending_start', 'rate_minor_per_kwh' => $tariff?->rate_minor_per_kwh ?? 100, 'currency' => $station->currency]);
            $finance->snapshotSession($s, $tariff, $priceConsentId);
            if ($rider) {
                abort_if(SessionLink::where('rider_token_id', $rider->id)->whereNull('ended_at')->whereNull('closed_without_dispatch_at')->exists(), 409, 'This rider token has an unresolved source session.');
            }
            $remote = ! $rider && config('csms.remote_authorization_enabled');
            $tag = $remote ? 'EV-'.strtoupper(bin2hex(random_bytes(8))) : 'EV-SIM-'.$s->id;
            $c = $rider ? null : $this->command($actor, $station->id, 'start', ['session_id' => $s->id, 'id_token' => $tag], 'csms-session:'.$s->id.':start', (int) $customer->auth_epoch);
            $link = SessionLink::create(['charging_session_id' => $s->id, 'binding_id' => $binding->id, 'start_command_id' => $c?->id, 'rider_token_id' => $rider?->id, 'rider_grant_id' => $rider ? $grantId : null, 'connector_mapping_id' => $r->connector_mapping_id, 'protocol' => $binding->protocol, 'identifier' => $binding->identifier, 'last_sequence' => -1, 'remote_token_digest' => $remote ? hash('sha256', $tag) : null, 'remote_registration_nonce' => $remote ? (string) Str::uuid() : null]);
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
        abort_if($candidate->start_command_id === null, 409, 'Source-authorized charging requires source reconciliation before capacity release.');
        $command = ChargerCommand::whereKey($candidate->start_command_id)->lockForUpdate()->firstOrFail();
        $link = SessionLink::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        $session = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
        abort_unless($link->start_command_id === $command->id && $command->status === 'pending' && $command->action === 'start' && $command->station_id === $reservation->station_id && (int) ($command->payload['session_id'] ?? 0) === $session->id && $session->reservation_id === $reservation->id && $session->status === 'pending_start' && $session->stopped_at === null && $session->total_minor === null && (int) $session->energy_wh === 0 && $link->transaction_id === null && $link->started_at === null && $link->ended_at === null && $link->last_sequence === -1 && $link->last_meter_wh === null && $link->stop_command_id === null && $link->closed_without_dispatch_at === null, 409, 'An admitted or uncertain charging start requires reconciliation; cancellation is unavailable.');
        abort_if(DB::table('csms_inbox_events')->where('link_id', $link->id)->exists(), 409, 'Observed source activity requires reconciliation.');
        $command->update(['status' => 'cancelled', 'failure_code' => $status === 'cancelled' ? 'cancelled_before_dispatch' : 'expired_before_dispatch']);
        $session->update(['status' => $status]);
        $link->update(['closed_without_dispatch_at' => now(), 'closure_reason' => $reason]);
    }

    public function stop(ChargingSession $session, int $actor, string $role, ?User $consentingActor = null, ?array $staffStop = null): array
    {
        $this->enabled();

        return DB::transaction(function () use ($session, $actor, $role, $consentingActor, $staffStop) {
            // Current credentials and station ownership are the actual stop
            // authority, not the route-bound request's stale user snapshot.
            $current = $consentingActor
                ? app(ChargingActorConsent::class)->capture($consentingActor)
                : User::whereKey($actor)->lockForUpdate()->firstOrFail();
            abort_unless($current->id === $actor && $current->role === $role
                && $current->account_status === 'active', 409, 'Charging account authorization changed.');
            $sourceSession = ChargingSession::whereKey($session->id)->firstOrFail();
            $reservation = Reservation::findOrFail($sourceSession->reservation_id);
            $station = Station::whereKey($reservation->station_id)->lockForUpdate()->firstOrFail();
            $r = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($staffStop !== null) {
                abort_unless($consentingActor, 404);
                $authority = app(VendorStaffStopAuthority::class);
                $staffStop = $authority->snapshot($consentingActor, $staffStop);
                abort_unless($authority->permits($current, $station, $staffStop, $current->auth_epoch), 404);
            } else {
                abort_unless(($role === 'customer' && $actor === $r->user_id)
                    || ($role === 'vendor' && $actor === $station->vendor_id)
                    || $role === 'admin', 404);
            }
            $link = SessionLink::where('charging_session_id', $session->id)->lockForUpdate()->firstOrFail();
            $s = ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->reservation_id === $r->id && $r->station_id === $station->id,
                409, 'Source session ownership changed during stop.');
            if ($staffStop !== null) {
                $binding = app(ConnectorTopology::class)->binding($r, true);
                abort_unless($r->connector_mapping_id && $link->connector_mapping_id === $r->connector_mapping_id
                    && $binding && $binding->id === $link->binding_id && $binding->identifier === $link->identifier
                    && $binding->protocol === $link->protocol && $binding->vendor_charger_id !== null,
                    409, 'The session is no longer linked to this simulator inventory.');
                abort_unless($link->stop_command_id || ($s->status === 'charging' && $link->started_at !== null
                    && $link->ended_at === null && $link->closed_without_dispatch_at === null
                    && OcppTransactionId::matchesStop($link->protocol, $link->transaction_id, $link->transaction_id)),
                    409, 'The simulator session can no longer be stopped.');
            }
            if ($link->stop_command_id || $s->status === 'stopped') {
                return $this->summary($link);
            }
            abort_unless($s->status === 'charging' && $link->transaction_id !== null, 409, 'Wait for the authoritative simulator transaction before stopping.');
            // The locked session link makes concurrent and repeated stops
            // one-shot. A cancelled, provably unsent delegated command releases
            // only its own link and cannot collide with a later explicit stop.
            $key = 'csms-session:'.$s->id.':stop'.($staffStop === null ? '' : ':'.Str::uuid());
            $c = $this->command($actor, $station->id, 'stop', ['session_id' => $s->id, 'transaction_id' => $link->transaction_id], $key,
                $current->auth_epoch, $staffStop);
            $link->update(['stop_command_id' => $c->id]);
            $s->update(['status' => 'stopping']);
            $this->audit($actor, $staffStop === null ? 'csms.stop_requested' : 'csms.staff_stop_requested', $s->id);

            return $this->summary($link);
        }, 3);
    }

    public function summary(SessionLink $link): array
    {
        return ['session' => ChargingSession::findOrFail($link->charging_session_id), 'link' => ['id' => $link->id, 'protocol' => $link->protocol, 'transaction_id' => $link->transaction_id, 'last_sequence' => $link->last_sequence, 'started_at' => $link->started_at, 'ended_at' => $link->ended_at, 'closed_without_dispatch_at' => $link->closed_without_dispatch_at, 'closure_reason' => $link->closure_reason],
            'start_command' => ChargerCommand::whereKey($link->start_command_id)->first(['id', 'status', 'failure_code']), 'stop_command' => $link->stop_command_id ? ChargerCommand::whereKey($link->stop_command_id)->first(['id', 'status', 'failure_code']) : null, 'evidence' => 'SIMULATED_CSMS', 'physical_charging_verified' => false];
    }

    public function ingest(array $v, ?callable $authorize = null): array
    {
        $this->enabled();
        try {
            $at = SourceStatusTime::parse($v['occurred_at'], 300);
        } catch (HttpExceptionInterface) {
            abort(422, 'Source event requires a valid absolute timestamp within the clock tolerance.');
        }
        abort_if($v['protocol'] === '1.6' && ! OcppTransactionId::valid16($v['transaction_id']), 422, 'Invalid OCPP 1.6 transaction identifier.');
        abort_if($at->gt(now()->addMinutes(5)), 422, 'Source event time is in the future.');
        $identity = [(int) $v['session_id'], $v['identifier'], $v['protocol'], $v['transaction_id'], $v['type'], (int) $v['sequence'], (int) $v['meter_wh'], $at->format('Y-m-d\TH:i:s.u\Z')];
        if (array_key_exists('source_evse_number', $v) || array_key_exists('source_connector_number', $v)) {
            $identity[] = $v['source_evse_number'] ?? null;
            $identity[] = $v['source_connector_number'] ?? null;
        }
        $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($v, $at, $hash, $authorize) {
            // Lock the station ahead of the session link: signed event data
            // alone cannot authorize a previously bound but now reparented
            // source, and source rebinding must not race invoice issuance.
            $sessionHint = ChargingSession::whereKey($v['session_id'])->firstOrFail();
            $reservationHint = Reservation::whereKey($sessionHint->reservation_id)->firstOrFail();
            $station = Station::whereKey($reservationHint->station_id)->lockForUpdate()->firstOrFail();
            $link = SessionLink::where('charging_session_id', $v['session_id'])->lockForUpdate()->firstOrFail();
            $session = ChargingSession::whereKey($link->charging_session_id)->firstOrFail();
            $reservation = Reservation::whereKey($session->reservation_id)->firstOrFail();
            abort_unless($session->id === $sessionHint->id
                && $reservation->id === $reservationHint->id
                && $reservation->station_id === $station->id
                && $station->source === 'simulator', 409,
                'Charging source topology changed; reconcile before posting source events.');
            $binding = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
            abort_unless($binding && $binding->station_id === $station->id
                && $binding->identifier === $link->identifier
                && $binding->protocol === $link->protocol
                && hash_equals($link->identifier, $v['identifier'])
                && $link->protocol === $v['protocol'], 409,
                'Signed source receipt no longer matches the canonical binding.');
            if ($binding->vendor_charger_id !== null) {
                $charger = VendorCharger::find($binding->vendor_charger_id);
                $site = $charger ? VendorSite::find($charger->site_id) : null;
                $company = $charger ? VendorCompany::find($charger->company_id) : null;
                abort_unless($charger && $site && $company
                    && $charger->source === 'simulator'
                    && $charger->ocpp_protocol === $binding->protocol
                    && $site->company_id === $company->id
                    && $site->station_id === $station->id
                    && $company->owner_id === $station->vendor_id
                    && $station->country === $company->country
                    && $site->country === $station->country, 409,
                    'Charger ownership or source binding changed.');
            }
            abort_unless($reservation->connector_mapping_id === $link->connector_mapping_id
                && $reservation->charging_evse_id === $session->charging_evse_id, 409,
                'Session reservation target changed; reconcile before posting energy.');
            if ($link->connector_mapping_id) {
                $map = ConnectorMapping::findOrFail($link->connector_mapping_id);
                $evse = Evse::findOrFail($map->evse_id);
                $connector = VendorConnector::find($map->connector_id);
                // Do not apply new-start maintenance or license policy here:
                // a genuine ongoing transaction must still be able to end.
                abort_unless($connector && $binding->vendor_charger_id !== null
                    && $evse->mode === 'csms'
                    && $evse->id === $session->charging_evse_id
                    && $connector->charger_id === $evse->charger_id
                    && $evse->charger_id === $binding->vendor_charger_id
                    && ($v['source_connector_number'] ?? null) === $map->source_connector_number
                    && ($v['source_evse_number'] ?? null) === $evse->source_evse_number
                    && $evse->binding_id === $binding->id
                    && $map->station_id === $station->id, 409,
                    'Canonical source target mismatch.');
            }
            abort_unless($link->closed_without_dispatch_at === null, 409, 'This request was closed before dispatch; unexpected source activity requires reconciliation.');
            // Even an idempotent duplicate is private authenticated evidence.
            if ($authorize !== null) {
                $authorize();
            }
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
            // The final session lock can also wait; revalidate before
            // applying energy, writing a receipt or issuing an invoice.
            if ($authorize !== null) {
                $authorize();
            }
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
        app(CommandReconciler::class)->forSession((int) $v['session_id']);

        return $result;
    }
}
