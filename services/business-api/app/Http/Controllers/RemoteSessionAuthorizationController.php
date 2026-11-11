<?php

namespace App\Http\Controllers;

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\OperatingCalendar;
use App\Services\Charging\RemoteSessionAuthorization;
use App\Services\Charging\RiderServiceAuth;
use App\Services\Charging\StationGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RemoteSessionAuthorizationController extends Controller
{
    public function __invoke(Request $r, RiderServiceAuth $auth, RemoteSessionAuthorization $source)
    {
        $auth->verify($r);
        $source->enabled();
        $v = $r->validate(['idToken' => ['required', 'regex:/^EV-[A-F0-9]{16}$/D'], 'idTokenType' => 'required|in:Central', 'ocppConnectionName' => ['required', 'regex:/^ev-test-[a-zA-Z0-9-]{1,80}$/D'], 'connectorId' => 'nullable|integer|min:1', 'evseId' => 'nullable|integer|min:0']);
        $allowed = false;
        try {
            $link = SessionLink::where('remote_token_digest', hash('sha256', $v['idToken']))->first();
            if ($link && $link->identifier === $v['ocppConnectionName'] && $link->remote_authorization_id && $link->connector_mapping_id && $link->rider_token_id === null && $source->verified($link, $v['idToken'])) {
                $binding = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
                $map = ConnectorMapping::find($link->connector_mapping_id);
                $evse = $map ? Evse::find($map->evse_id) : null;
                if ($binding && $evse && $evse->binding_id === $binding->id && $map->station_id === $binding->station_id && app(RiderSourceController::class)->targetMatches($binding, $map, $evse, $v)) {
                    $allowed = DB::transaction(function () use ($link, $binding, $v) {
                        $station = Station::whereKey($binding->station_id)->lockForUpdate()->firstOrFail();
                        app(StationGate::class)->requireReady($station, $link->connector_mapping_id);
                        $session = ChargingSession::findOrFail($link->charging_session_id);
                        if ($session->status === 'pending_start') {
                            app(OperatingCalendar::class)->requireStart($station);
                        }
                        $reservation = Reservation::whereKey($session->reservation_id)->lockForUpdate()->firstOrFail();
                        $current = SessionLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
                        $command = ChargerCommand::whereKey($current->start_command_id)->lockForUpdate()->firstOrFail();
                        $start = CarbonImmutable::parse($reservation->starts_at);

                        return $current->ended_at === null && $current->closed_without_dispatch_at === null && hash_equals($current->remote_token_digest, hash('sha256', $v['idToken'])) && $command->actor_id === $reservation->user_id && ($command->payload['id_token'] ?? null) === $v['idToken'] && in_array($command->status, ['dispatching', 'submitted', 'outcome_unknown', 'source_observed'], true) && $reservation->station_id === $station->id && $reservation->connector_mapping_id === $current->connector_mapping_id && $reservation->status === 'confirmed' && User::whereKey($reservation->user_id)->where('role', 'customer')->exists() && in_array($session->status, ['pending_start', 'charging', 'stopping'], true) && now()->gte($start) && now()->lt($start->addMinutes(30));
                    });
                }
            }
        } catch (Throwable) {
            $allowed = false;
        }

        return response()->json(['timestamp' => now()->toISOString(), 'data' => ['allowed' => $allowed ? 'ALLOWED' : 'NOT_ALLOWED']])->header('Cache-Control', 'private, no-store');
    }
}
