<?php

namespace App\Services;

use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
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
        $command = DB::transaction(function () use ($id) {
            $c = ChargerCommand::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($c->status === 'pending') {
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
                            app(StationGate::class)->requireReady(Station::findOrFail($c->station_id));
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
            return ChargerCommand::findOrFail($id);
        }
        try {
            $b = DB::table('csms_bindings')->where('station_id', $command->station_id)->first();
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
                $payload = $command->action === 'start' ? ['idTag' => $command->payload['id_token'] ?? 'EV-SIMULATOR', 'connectorId' => 1] : ['transactionId' => (int) $command->payload['transaction_id']];
            } else {
                $action = $command->action === 'start' ? 'requestStartTransaction' : 'requestStopTransaction';
                $payload = $command->action === 'start' ? ['idToken' => ['idToken' => $command->payload['id_token'] ?? 'EV-SIMULATOR', 'type' => 'Central'], 'remoteStartId' => $command->id, 'evseId' => 1] : ['transactionId' => $command->payload['transaction_id']];
            }
            $response = Http::withToken($token)->timeout(25)->post($base.'/ocpp/'.$b->protocol.'/evdriver/'.$action.'?'.http_build_query(['identifier' => $b->identifier, 'tenantId' => 1]), $payload)->throw();
            $confirmation = $response->json();
            $submitted = is_array($confirmation) && count($confirmation) === 1 && ($confirmation[0]['success'] ?? null) === true;
            $rejected = is_array($confirmation) && count($confirmation) === 1 && ($confirmation[0]['success'] ?? null) === false;
            $command->update(['status' => $submitted ? 'submitted' : ($rejected ? 'rejected' : 'outcome_unknown'), 'confirmation' => $confirmation, 'failure_code' => $submitted ? null : ($rejected ? 'csms_rejected_dispatch' : 'unexpected_confirmation')]);
        } catch (\Throwable $e) {
            // A timeout can occur after delivery. Never replay an uncertain start automatically.
            $command->update(['status' => 'outcome_unknown', 'failure_code' => 'dispatch_requires_reconciliation']);
        }

        return $command->fresh();
    }
}
