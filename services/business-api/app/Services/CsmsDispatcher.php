<?php

namespace App\Services;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\StationGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CsmsDispatcher
{
    public function dispatch(int $id): ChargerCommand
    {
        abort_unless(config('csms.simulator_enabled') && ! app()->environment('production'), 503, 'Simulator dispatch is disabled.');
        app(CommandReconciler::class)->forCommand($id);
        $stationId = ChargerCommand::findOrFail($id)->station_id;
        $command = DB::transaction(function () use ($id, $stationId) {
            Station::whereKey($stationId)->lockForUpdate()->firstOrFail();
            $c = ChargerCommand::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($c->station_id === $stationId, 409, 'Command station changed.');
            if ($c->status === 'pending') {
                if (isset($c->payload['session_id'])) {
                    $linked = SessionLink::where('charging_session_id', $c->payload['session_id'])->first();
                    if ($linked && ($linked->closed_without_dispatch_at !== null || ($c->action === 'start' ? $linked->started_at !== null : $linked->ended_at !== null))) {
                        $c->update(['failure_code' => 'source_observation_requires_reconciliation']);

                        return null;
                    }
                }
                if ($c->action === 'start') {
                    $blocked = null;
                    if (isset($c->payload['session_id'])) {
                        $session = ChargingSession::findOrFail($c->payload['session_id']);
                        $booking = Reservation::findOrFail($session->reservation_id);
                        $start = CarbonImmutable::parse($booking->starts_at, 'UTC');
                        if ($booking->status !== 'confirmed' || ! now()->gte($start) || ! now()->lt($start->addMinutes(30))) {
                            $blocked = 'pre_dispatch_window_unavailable';
                        }
                    }
                    if (! $blocked) {
                        try {
                            app(StationGate::class)->requireReady(Station::findOrFail($c->station_id), isset($booking) ? $booking->connector_mapping_id : null);
                        } catch (HttpException $e) {
                            if ($e->getStatusCode() !== 409) {
                                throw $e;
                            }
                            $blocked = 'pre_dispatch_company_gate';
                        }
                    }
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
        });
        if (! $command) {
            app(CommandReconciler::class)->forCommand($id);

            return ChargerCommand::findOrFail($id);
        }
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
            $auth = Http::timeout(5)->post($base.'/dev/token', ['roles' => ['admin'], 'tenantId' => 1])->throw();
            $token = $auth->json('accessToken');
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Missing local token');
            }
            if ($b->protocol === '1.6') {
                $action = $command->action === 'start' ? 'remoteStartTransaction' : 'remoteStopTransaction';
                if ($command->action === 'stop' && ! ctype_digit($command->payload['transaction_id'])) {
                    throw new \RuntimeException('OCPP 1.6 transaction id must be numeric');
                }
                $payload = $command->action === 'start' ? ['idTag' => $command->payload['id_token'] ?? 'EV-SIMULATOR', 'connectorId' => $sourceConnector] : ['transactionId' => (int) $command->payload['transaction_id']];
            } else {
                $action = $command->action === 'start' ? 'requestStartTransaction' : 'requestStopTransaction';
                $payload = $command->action === 'start' ? ['idToken' => ['idToken' => $command->payload['id_token'] ?? 'EV-SIMULATOR', 'type' => 'Central'], 'remoteStartId' => $command->id, 'evseId' => $sourceEvse] : ['transactionId' => $command->payload['transaction_id']];
            }
            $response = Http::withToken($token)->timeout(25)->post($base.'/ocpp/'.$b->protocol.'/evdriver/'.$action.'?'.http_build_query(['identifier' => $b->identifier, 'tenantId' => 1]), $payload)->throw();
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
            // A timeout can occur after delivery. Never replay an uncertain start automatically.
            ChargerCommand::whereKey($command->id)->where('status', 'dispatching')->update(['status' => 'outcome_unknown', 'failure_code' => 'dispatch_requires_reconciliation']);
        }

        return $command->fresh();
    }
}
