<?php

namespace App\Services;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ChargingActorConsent;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\OcppTransactionId;
use App\Services\Charging\OperatingCalendar;
use App\Services\Charging\OperationsCredentials;
use App\Services\Charging\StationGate;
use App\Services\Charging\VendorStaffStopAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CsmsDispatcher
{
    /** Check the current canonical target before any pending device command is claimed. */
    private function sourceLineage(ChargerCommand $command): bool
    {
        $station = Station::find($command->station_id);
        if (! $station || $station->source !== 'simulator') {
            return false;
        }
        $sessionId = $command->payload['session_id'] ?? null;
        $link = $sessionId === null ? null : SessionLink::where('charging_session_id', $sessionId)->first();
        if ($sessionId !== null && (! $link || $link->closed_without_dispatch_at !== null
            || ($command->action === 'start' ? $link->start_command_id : $link->stop_command_id) !== $command->id)) {
            return false;
        }
        // The transport payload is frozen at stop request time. A changed
        // canonical transaction cannot authorize stopping its former ID.
        if ($link && $command->action === 'stop'
            && (! $link->started_at || ! OcppTransactionId::matchesStop(
                $link->protocol, $command->payload['transaction_id'] ?? null, $link->transaction_id
            ))) {
            return false;
        }
        if (! $link && $command->action === 'stop'
            && ((int) $command->actor_id !== (int) $station->vendor_id
                || ! User::whereKey($command->actor_id)->where('role', 'vendor')
                    ->where('account_status', 'active')->exists())) {
            // The legacy station-wide stop has no reservation owner/target
            // lineage. After station transfer or vendor suspension, never
            // send it into another operator's charge point.
            return false;
        }
        if ($link) {
            $binding = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
            if (! $binding || $binding->station_id !== $station->id
                || $binding->identifier !== $link->identifier || $binding->protocol !== $link->protocol) {
                return false;
            }
        } else {
            $bindings = DB::table('csms_bindings')->where('station_id', $station->id)->limit(2)->get();
            if ($bindings->count() !== 1 || ConnectorMapping::where('station_id', $station->id)->exists()) {
                return false;
            }
            $binding = $bindings->first();
        }
        if (! in_array($binding->protocol, ['1.6', '2.0.1'], true)
            || ! preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $binding->identifier)) {
            return false;
        }
        if ($binding->vendor_charger_id === null) {
            // Legacy single-binding simulator only. A canonical mapped
            // session must always be tied to one owned physical inventory row.
            return ! $link?->connector_mapping_id
                && ! ConnectorMapping::where('station_id', $station->id)->exists();
        }
        $charger = VendorCharger::find($binding->vendor_charger_id);
        $site = $charger ? VendorSite::find($charger->site_id) : null;
        $company = $charger ? VendorCompany::find($charger->company_id) : null;
        if (! $charger || ! $site || ! $company
            || $charger->source !== 'simulator' || $charger->ocpp_protocol !== $binding->protocol
            || $site->station_id !== $station->id || $site->company_id !== $company->id
            || $company->owner_id !== $station->vendor_id
            || $company->country !== $station->country || $site->country !== $station->country) {
            return false;
        }
        if ($link?->connector_mapping_id) {
            $map = ConnectorMapping::find($link->connector_mapping_id);
            $evse = $map ? Evse::find($map->evse_id) : null;
            if (! $map || ! $evse || $map->station_id !== $station->id
                || $evse->binding_id !== $binding->id || $evse->charger_id !== $charger->id) {
                return false;
            }
        }

        return true;
    }

    /** Re-evaluate current start consent and capacity; never gate a legitimate stop on new-start policy. */
    private function startBlocked(User $actor, Station $station, ChargerCommand $command): ?string
    {
        $blocked = app(ChargingActorConsent::class)->matches($actor, $command->consent_auth_epoch) ? null : 'pre_dispatch_actor_revoked';
        if (! $blocked && ! User::whereKey($station->vendor_id)->where('role', 'vendor')->where('account_status', 'active')->exists()) {
            $blocked = 'pre_dispatch_vendor_unavailable';
        }
        if (! $blocked && isset($command->payload['session_id'])) {
            $session = ChargingSession::findOrFail($command->payload['session_id']);
            $booking = Reservation::findOrFail($session->reservation_id);
            if ($actor->role !== 'customer' || $booking->user_id !== $actor->id) {
                $blocked = 'pre_dispatch_actor_revoked';
            }
            $start = CarbonImmutable::parse($booking->starts_at, 'UTC');
            if ($booking->status !== 'confirmed' || ! now()->gte($start) || ! now()->lt($start->addMinutes(30))) {
                $blocked = 'pre_dispatch_window_unavailable';
            }
        }
        if (! $blocked && ! isset($booking) && ! ($actor->role === 'vendor' && $station->vendor_id === $actor->id)) {
            $blocked = 'pre_dispatch_actor_revoked';
        }
        if (! $blocked) {
            try {
                app(StationGate::class)->requireReady($station, isset($booking) ? $booking->connector_mapping_id : null);
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
                $blocked = 'pre_dispatch_company_gate';
            }
        }
        if (! $blocked) {
            try {
                app(OperatingCalendar::class)->requireStart($station);
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
                $blocked = 'pre_dispatch_operating_calendar';
            }
        }

        return $blocked;
    }

    private function delegatedStopAllowed(User $actor, Station $station, ChargerCommand $command): bool
    {
        return $command->getRawOriginal('staff_stop_authority') === null
            || ($command->action === 'stop' && app(VendorStaffStopAuthority::class)
                ->permits($actor, $station, $command->staff_stop_authority, $command->consent_auth_epoch));
    }

    /** Caller holds the station and command locks and has never entered the device transport. */
    private function cancelUnsentDelegatedStop(ChargerCommand $command): void
    {
        $command->update(['status' => 'cancelled', 'failure_code' => 'pre_dispatch_staff_stop_revoked']);
        DB::table('audit_events')->insert(['actor_id' => $command->actor_id, 'action' => 'csms.staff_stop_cancelled_before_dispatch',
            'subject' => (string) $command->id, 'created_at' => now()]);
        $sessionId = $command->payload['session_id'] ?? null;
        if ($command->action !== 'stop' || ! is_int($sessionId)) {
            return;
        }
        $link = SessionLink::where('charging_session_id', $sessionId)->where('stop_command_id', $command->id)
            ->lockForUpdate()->first();
        if ($link && $link->ended_at === null) {
            $link->update(['stop_command_id' => null]);
            ChargingSession::whereKey($sessionId)->where('status', 'stopping')->update(['status' => 'charging']);
        }
    }

    public function dispatch(int $id): ChargerCommand
    {
        abort_unless(config('csms.simulator_enabled') && ! app()->environment('production'), 503, 'Simulator dispatch is disabled.');
        app(CommandReconciler::class)->forCommand($id);
        $hint = ChargerCommand::findOrFail($id);
        $stationId = $hint->station_id;
        $actorId = $hint->actor_id;
        $command = DB::transaction(function () use ($id, $stationId, $actorId) {
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            $station = Station::whereKey($stationId)->lockForUpdate()->firstOrFail();
            $c = ChargerCommand::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($c->station_id === $stationId && $c->actor_id === $actorId, 409, 'Command station changed.');
            // Never overwrite a retained authority/lineage fence with a
            // transient station-source failure that could later be cleared.
            if ($c->status === 'pending' && in_array($c->failure_code, ['pre_dispatch_authority_changed', 'pre_dispatch_source_lineage_changed'], true)) {
                return null;
            }
            // An accepted simulator command is never transportable after
            // its canonical station changes source (including stop commands).
            // Do not re-target a queued outbox row or attempt external I/O.
            if ($station->source !== 'simulator') {
                if ($c->status === 'pending') {
                    $c->failure_code = 'pre_dispatch_source_changed';
                    $c->save();
                }

                return null;
            }
            if ($c->status === 'pending') {
                // Repointed canonical inventory or source identity must not
                // redirect an existing pending command to a different owner
                // or device. Keep the outbox pending, provably unsent.
                if (! $this->sourceLineage($c)) {
                    $c->failure_code = 'pre_dispatch_source_lineage_changed';
                    $c->save();

                    return null;
                }
                if (! $this->delegatedStopAllowed($actor, $station, $c)) {
                    $this->cancelUnsentDelegatedStop($c);

                    return null;
                }
                if (isset($c->payload['session_id'])) {
                    $linked = SessionLink::where('charging_session_id', $c->payload['session_id'])->first();
                    if ($linked && ($linked->closed_without_dispatch_at !== null || ($c->action === 'start' ? $linked->started_at !== null : $linked->ended_at !== null))) {
                        $c->update(['failure_code' => 'source_observation_requires_reconciliation']);

                        return null;
                    }
                }
                if ($c->action === 'stop') {
                    $linked = isset($c->payload['session_id'])
                        ? SessionLink::where('charging_session_id', $c->payload['session_id'])->first()
                        : null;
                    $protocol = $linked?->protocol
                        ?? DB::table('csms_bindings')->where('station_id', $stationId)->value('protocol');
                    if ($protocol === '1.6' && ! OcppTransactionId::valid16($c->payload['transaction_id'] ?? null)) {
                        $c->failure_code = 'pre_dispatch_invalid_transaction_id';
                        $c->save();

                        return null;
                    }
                }
                if ($c->action === 'start') {
                    $blocked = $this->startBlocked($actor, $station, $c);
                    if ($blocked) {
                        $c->failure_code = $blocked;
                        $c->save();

                        return null; // Still pending and provably not sent; never claim a source transaction ended.
                    }
                }
                $c->failure_code = null;
                $c->status = 'dispatching';
                $c->save();

                return $c;
            }

            return null;
        }, 3);
        if (! $command) {
            app(CommandReconciler::class)->forCommand($id);

            return ChargerCommand::findOrFail($id);
        }
        $wireAttempted = false;
        $verifiedPrewireSourceMismatch = false;
        $verifiedPrewireAuthorityMismatch = false;
        try {
            $link = isset($command->payload['session_id']) ? SessionLink::where('charging_session_id', $command->payload['session_id'])->first() : null;
            if ($link) {
                if (($command->action === 'start' ? $link->start_command_id : $link->stop_command_id) !== $command->id) {
                    throw new \RuntimeException('Command lineage mismatch');
                }
                $b = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
                if (! $b || $link->identifier !== $b->identifier || $link->protocol !== $b->protocol || $b->station_id !== $command->station_id) {
                    throw new \RuntimeException('Source lineage mismatch');
                }
            } else {
                $bindings = DB::table('csms_bindings')->where('station_id', $command->station_id)->get();
                if ($bindings->count() !== 1 || ConnectorMapping::where('station_id', $command->station_id)->exists()) {
                    throw new \RuntimeException('Explicit session target required');
                }
                $b = $bindings->first();
            }
            $sourceConnector = 1;
            $sourceEvse = 1;
            if ($link?->connector_mapping_id) {
                $map = ConnectorMapping::findOrFail($link->connector_mapping_id);
                $evse = Evse::findOrFail($map->evse_id);
                if ($map->station_id !== $command->station_id || $evse->binding_id !== $b->id) {
                    throw new \RuntimeException('Mapping lineage mismatch');
                }
                $sourceConnector = $map->source_connector_number;
                $sourceEvse = $evse->source_evse_number;
            }
            if (! $b || ! preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/', $b->identifier)) {
                throw new \RuntimeException('Invalid simulator binding');
            }
            $base = config('csms.base_url');
            $token = app(OperationsCredentials::class)->token($base);
            if ($b->protocol === '1.6') {
                $action = $command->action === 'start' ? 'remoteStartTransaction' : 'remoteStopTransaction';
                if ($command->action === 'stop' && ! OcppTransactionId::valid16($command->payload['transaction_id'] ?? null)) {
                    throw new \RuntimeException('OCPP 1.6 transaction id must be numeric');
                }
                $payload = $command->action === 'start' ? ['idTag' => $command->payload['id_token'] ?? 'EV-SIMULATOR', 'connectorId' => $sourceConnector] : ['transactionId' => (int) $command->payload['transaction_id']];
            } else {
                $action = $command->action === 'start' ? 'requestStartTransaction' : 'requestStopTransaction';
                $payload = $command->action === 'start' ? ['idToken' => ['idToken' => $command->payload['id_token'] ?? 'EV-SIMULATOR', 'type' => 'Central'], 'remoteStartId' => $command->id, 'evseId' => $sourceEvse] : ['transactionId' => $command->payload['transaction_id']];
            }
            // Source lineage can be repointed after the claim transaction
            // but before the slow, authenticated token fetch completes.
            // This second check prevents a proved-unsent remote command
            // from being delivered under the wrong station identity.
            $freshBinding = DB::table('csms_bindings')->where('id', $b->id)->first();
            if (! $freshBinding || $freshBinding->identifier !== $b->identifier
                || $freshBinding->protocol !== $b->protocol
                || $freshBinding->station_id !== $b->station_id
                || ($freshBinding->vendor_charger_id ?? null) !== ($b->vendor_charger_id ?? null)
                || ! $this->sourceLineage($command)) {
                $verifiedPrewireSourceMismatch = true;
                throw new \RuntimeException('Pre-dispatch source lineage changed');
            }
            // Authentication is a network boundary: consent, booking,
            // availability, adapter state and connector numbers may have changed.
            $authorized = DB::transaction(function () use ($command, $base, $b, $link, $sourceConnector, $sourceEvse) {
                $actor = User::whereKey($command->actor_id)->lockForUpdate()->firstOrFail();
                $station = Station::whereKey($command->station_id)->lockForUpdate()->firstOrFail();
                $current = ChargerCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();
                if (! config('csms.simulator_enabled') || app()->environment('production')
                    || config('csms.base_url') !== $base || $current->status !== 'dispatching'
                    || $current->actor_id !== $command->actor_id || $current->station_id !== $command->station_id
                    || $current->action !== $command->action || $current->payload !== $command->payload
                    || $current->consent_auth_epoch !== $command->consent_auth_epoch
                    || $current->staff_stop_authority !== $command->staff_stop_authority
                    || ! $this->sourceLineage($current)) {
                    return false;
                }
                $binding = DB::table('csms_bindings')->where('id', $b->id)->first();
                if (! $binding || $binding->identifier !== $b->identifier || $binding->protocol !== $b->protocol
                    || $binding->station_id !== $b->station_id || $binding->vendor_charger_id !== $b->vendor_charger_id) {
                    return false;
                }
                if ($link) {
                    $latest = SessionLink::whereKey($link->id)->first();
                    if (! $latest || $latest->connector_mapping_id !== $link->connector_mapping_id
                        || $latest->binding_id !== $b->id || $latest->closed_without_dispatch_at !== null
                        || ($current->action === 'start' ? $latest->started_at !== null : $latest->ended_at !== null)) {
                        return false;
                    }
                    if ($latest->connector_mapping_id) {
                        $mapping = ConnectorMapping::find($latest->connector_mapping_id);
                        $evse = $mapping ? Evse::find($mapping->evse_id) : null;
                        if (! $mapping || ! $evse || $mapping->source_connector_number !== $sourceConnector
                            || $evse->source_evse_number !== $sourceEvse) {
                            return false;
                        }
                    }
                }

                if (! $this->delegatedStopAllowed($actor, $station, $current)) {
                    $this->cancelUnsentDelegatedStop($current);

                    return false;
                }

                return $current->action !== 'start' || $this->startBlocked($actor, $station, $current) === null;
            }, 3);
            if (! $authorized) {
                $verifiedPrewireAuthorityMismatch = true;
                throw new \RuntimeException('Pre-dispatch authority changed');
            }
            $wireAttempted = true;
            $response = Http::withToken($token)->timeout(25)->withoutRedirecting()->post($base.'/ocpp/'.$b->protocol.'/evdriver/'.$action.'?'.http_build_query(['identifier' => $b->identifier, 'tenantId' => 1]), $payload)->throw();
            $confirmation = $response->json();
            $submitted = is_array($confirmation) && count($confirmation) === 1 && ($confirmation[0]['success'] ?? null) === true;
            $rejected = is_array($confirmation) && count($confirmation) === 1 && ($confirmation[0]['success'] ?? null) === false;
            DB::transaction(function () use ($command, $submitted, $rejected, $confirmation) {
                $current = ChargerCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();
                if (! in_array($current->status, ['dispatching', 'source_observed'], true)) {
                    return;
                }
                // Retain a late transport reply without replacing stronger source evidence.
                $current->confirmation = $confirmation;
                if ($current->status === 'dispatching') {
                    $current->status = $submitted ? 'submitted' : ($rejected ? 'rejected' : 'outcome_unknown');
                    $current->failure_code = $submitted ? null : ($rejected ? 'csms_rejected_dispatch' : 'unexpected_confirmation');
                }
                $current->save();
            }, 3);
        } catch (\Throwable $e) {
            // Preserve the existing quarantine for rejected/unverifiable
            // service credentials: no automatic retry of failed CSMS auth.
            // Only an affirmative local source or authority mismatch detected
            // before entering the HTTP wire is provably unsent and may stay
            // pending for explicit resolution, without automatic retargeting.
            $provenUnsent = ! $wireAttempted && ($verifiedPrewireSourceMismatch || $verifiedPrewireAuthorityMismatch);
            ChargerCommand::whereKey($command->id)->where('status', 'dispatching')->update(
                $provenUnsent
                    ? ['status' => 'pending', 'failure_code' => $verifiedPrewireAuthorityMismatch ? 'pre_dispatch_authority_changed' : 'pre_dispatch_source_lineage_changed']
                    : ['status' => 'outcome_unknown', 'failure_code' => 'dispatch_requires_reconciliation'],
            );
        }

        return $command->fresh();
    }
}
