<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Authenticated source reads only. No command, availability or billing decision. */
class ChargerDiagnosticsController extends Controller
{
    private function binding(Request $r, VendorCompany $company, VendorCharger $charger): object
    {
        app(VendorAccess::class)->require($r->user(), $company, 'inventory.read');
        abort_unless($charger->company_id === $company->id, 404);
        $site = VendorSite::findOrFail($charger->site_id);
        $station = Station::find($site->station_id);
        abort_unless($site->company_id === $company->id && $station && $station->vendor_id === $company->owner_id && $station->source === 'simulator' && $charger->source === 'simulator', 409, 'An owned simulator source mapping is required.');
        $bindings = DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->get();
        abort_unless($bindings->count() === 1, 409, 'One canonical source mapping is required.');
        $b = $bindings->first();
        abort_unless($b->station_id === $station->id && $b->protocol === $charger->ocpp_protocol && in_array($b->protocol, ['1.6', '2.0.1'], true) && str_starts_with($b->identifier, 'ev-test-'), 409, 'Source identity requires review.');

        return $b;
    }

    private function graph(string $query, array $variables): array
    {
        $secret = (string) config('csms.graphql_secret');
        abort_unless(config('csms.session_bridge_enabled') && ! app()->environment('production') && strlen($secret) >= 32, 503, 'Authenticated simulator source is unavailable.');
        try {
            $reply = Http::timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $secret])->post(config('csms.graphql_url'), compact('query', 'variables'));
            $data = $reply->json('data');
        } catch (Throwable) {
            abort(503, 'Authenticated simulator source is unavailable.');
        }
        abort_unless($reply->successful() && ! $reply->json('errors') && is_array($data), 503, 'Authenticated simulator source is unavailable.');

        return $data;
    }

    public function __invoke(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $v = $r->validate(['before' => 'sometimes|integer|min:1|max:2147483647']);
        $binding = $this->binding($r, $company, $charger);
        $data = $this->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id protocol isOnline firmwareVersion latestOcppMessageTimestamp}}', ['name' => $binding->identifier]);
        $rows = $data['ChargingStations'] ?? null;
        abort_unless(is_array($rows) && count($rows) <= 1, 409, 'Ambiguous source identity requires review.');
        $source = $rows[0] ?? null;
        $events = [];
        if ($source) {
            abort_unless(is_int($source['id'] ?? null) && is_bool($source['isOnline'] ?? null) && in_array($source['protocol'] ?? null, [$binding->protocol, 'ocpp'.$binding->protocol], true), 409, 'Source protocol or identity requires review.');
            $history = $this->graph('query($station:Int!,$before:Int!){StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station},id:{_lt:$before}},order_by:{id:desc},limit:26){id connectorId evseId connectorStatus errorCode timestamp}}', ['station' => $source['id'], 'before' => (int) ($v['before'] ?? 2147483647)]);
            $events = $history['StatusNotifications'] ?? null;
            abort_unless(is_array($events) && count($events) <= 26, 503, 'Source status history is unavailable.');
        }
        // No lock spans network calls. Fence permission/ownership/mapping changes before disclosure.
        $current = $this->binding($r, VendorCompany::findOrFail($company->id), VendorCharger::findOrFail($charger->id));
        abort_unless($current->id === $binding->id && $current->identifier === $binding->identifier && $current->protocol === $binding->protocol && $current->station_id === $binding->station_id, 409, 'Source mapping changed; refresh before inspection.');
        $last = null;
        if ($source && ($source['latestOcppMessageTimestamp'] ?? null)) {
            try {
                $last = CarbonImmutable::parse($source['latestOcppMessageTimestamp']);
            } catch (Throwable) {
                abort(503, 'Source timestamp is invalid.');
            }
        }
        $recent = $last !== null && $last->lessThanOrEqualTo(now()) && $last->greaterThanOrEqualTo(now()->subMinutes(5));
        $more = count($events) > 25;
        $previous = (int) ($v['before'] ?? 2147483647);
        foreach ($events as $event) {
            abort_unless(is_array($event) && is_int($event['id'] ?? null) && $event['id'] > 0 && $event['id'] < $previous && is_string($event['connectorStatus'] ?? null) && strlen($event['connectorStatus']) <= 40 && is_string($event['timestamp'] ?? null) && strlen($event['timestamp']) <= 50 && (! isset($event['errorCode']) || (is_string($event['errorCode']) && strlen($event['errorCode']) <= 80)), 503, 'Source status history is invalid.');
            $previous = $event['id'];
        }
        $events = array_slice($events, 0, 25);

        return response()->json([
            'evidence' => 'SIMULATED_SOURCE', 'protocol' => $binding->protocol,
            'observed_at' => now()->toISOString(), 'source_registered' => $source !== null,
            'source_online' => $source['isOnline'] ?? null, 'last_message_at' => $last?->toISOString(),
            'recent_message_observed' => $recent, 'freshness_seconds' => 300,
            'firmware_version' => isset($source['firmwareVersion']) ? mb_substr((string) $source['firmwareVersion'], 0, 100) : null,
            'events' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'connectorId', 'evseId', 'connectorStatus', 'errorCode', 'timestamp'])), $events),
            'next_before' => $more ? (int) end($events)['id'] : null,
            'physical_charging_verified' => false, 'commands_sent' => false,
        ])->header('Cache-Control', 'private, no-store');
    }
}
