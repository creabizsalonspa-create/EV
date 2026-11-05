<?php

namespace App\Http\Controllers;

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\RiderAccessToken;
use App\Models\Reservation;
use App\Models\Station;
use App\Services\Charging\RiderServiceAuth;
use App\Services\Charging\SessionBridge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RiderSourceController extends Controller
{
    private function targetMatches(object $binding, ConnectorMapping $map, Evse $evse, array $v): bool
    {
        if (empty($v['connectorId']) || strlen((string) config('csms.graphql_secret')) < 32) {
            return false;
        }
        try {
            $reply = Http::timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), ['query' => 'query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol}}', 'variables' => ['name' => $binding->identifier]]);
            $stations = $reply->json('data.ChargingStations');
            if (! $reply->successful() || $reply->json('errors') || ! is_array($stations) || count($stations) !== 1 || $stations[0]['isOnline'] !== true || ! in_array($stations[0]['protocol'], [$binding->protocol, 'ocpp'.$binding->protocol], true)) {
                return false;
            }
            $reply = Http::timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), ['query' => 'query($station:Int!,$connector:Int!){Connectors(where:{tenantId:{_eq:1},stationId:{_eq:$station}},limit:2){id} StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station},connectorDatabaseId:{_eq:$connector}},order_by:{id:desc},limit:1){evseId connectorId connectorStatus timestamp}}', 'variables' => ['station' => $stations[0]['id'], 'connector' => (int) $v['connectorId']]]);
            $connectors = $reply->json('data.Connectors');
            $status = $reply->json('data.StatusNotifications.0');
            if (! $reply->successful() || $reply->json('errors') || ! is_array($connectors) || count($connectors) !== 1 || (int) $connectors[0]['id'] !== (int) $v['connectorId'] || ! is_array($status)) {
                return false;
            }
            $at = CarbonImmutable::parse($status['timestamp']);

            return (int) ($status['evseId'] ?? 0) === (int) $evse->source_evse_number && (int) $status['connectorId'] === (int) $map->source_connector_number && ! in_array($status['connectorStatus'], ['Faulted', 'Unavailable'], true) && $at->lte(now()) && $at->gte(now()->subMinutes(5));
        } catch (Throwable) {
            return false;
        }
    }

    public function token(Request $r, RiderServiceAuth $auth)
    {
        return response()->json(['access_token' => $auth->issue($r), 'token_type' => 'Bearer', 'expires_in' => 120])->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    public function authorize(Request $r, RiderServiceAuth $auth, SessionBridge $bridge)
    {
        $auth->verify($r);
        $v = $r->validate(['idToken' => ['required', 'string', 'regex:/^[a-zA-Z0-9]{6,20}$/D'], 'idTokenType' => 'required|in:Central,ISO14443,ISO15693', 'ocppConnectionName' => ['required', 'regex:/^ev-test-[a-zA-Z0-9-]{1,80}$/D'], 'evseId' => 'nullable|integer|min:0', 'connectorId' => 'nullable|integer|min:0']);
        $decision = 'BLOCKED';
        $token = RiderAccessToken::where('token_digest', hash('sha256', strtoupper($v['idToken'])))->where('token_type', $v['idTokenType'])->first();
        if ($token && $token->revoked_at === null && $token->expires_at->isPast()) {
            $decision = 'EXPIRED';
        }
        if ($token && $token->revoked_at === null && $token->expires_at->isFuture() && $token->provisioning_state === 'active' && $token->provisioned_at !== null) {
            $bindings = DB::table('csms_bindings')->where('identifier', $v['ocppConnectionName'])->limit(2)->get();
            if ($bindings->count() === 1) {
                $binding = $bindings->first();
                $grants = DB::table('rider_charging_grants')->where('token_id', $token->id)->where('station_id', $binding->station_id)->where('state', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->limit(2)->get();
                $slots = Reservation::where('user_id', $token->user_id)->where('station_id', $binding->station_id)->where('status', 'confirmed')->where('starts_at', '<=', now())->where('starts_at', '>', now()->subMinutes(30))->limit(2)->get();
                if ($grants->count() === 1 && $slots->count() === 1 && $slots->first()->connector_mapping_id) {
                    // This first adapter admits only a uniquely mapped source target.
                    // Ambiguous/multi-target preauthorization fails closed rather than
                    // turning a station-level Authorize into an unrestricted grant.
                    $map = ConnectorMapping::find($slots->first()->connector_mapping_id);
                    $evse = $map ? Evse::find($map->evse_id) : null;
                    $mapped = ConnectorMapping::whereIn('evse_id', Evse::where('binding_id', $binding->id)->select('id'))->count();
                    if ($evse && $evse->binding_id === $binding->id && $mapped === 1 && $this->targetMatches($binding, $map, $evse, $v)) {
                        try {
                            $bridge->start($slots->first(), $token->user_id, $token, $grants->first()->id);
                            $decision = 'ALLOWED';
                        } catch (HttpExceptionInterface) {
                            $decision = 'NOT_ALLOWED';
                        }
                    } else {
                        $decision = 'NOT_ALLOWED';
                    }
                } else {
                    $decision = 'NOT_ALLOWED';
                }
            }
        }

        return response()->json(['timestamp' => now()->toISOString(), 'data' => ['allowed' => $decision]])->header('Cache-Control', 'private, no-store');
    }
}
