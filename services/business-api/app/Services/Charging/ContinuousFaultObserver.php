<?php

namespace App\Services\Charging;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Read-only source consumer. It never changes availability, sessions or commands. */
class ContinuousFaultObserver
{
    private function scope(int $id): array
    {
        $b = DB::table('csms_bindings')->where('id', $id)->first();
        abort_unless($b && $b->vendor_charger_id && in_array($b->protocol, ['1.6', '2.0.1'], true) && str_starts_with($b->identifier, 'ev-test-'), 409);
        $charger = VendorCharger::findOrFail($b->vendor_charger_id);
        $site = VendorSite::findOrFail($charger->site_id);
        $company = VendorCompany::findOrFail($charger->company_id);
        $station = Station::findOrFail($b->station_id);
        abort_unless($charger->source === 'simulator' && $station->source === 'simulator' && $site->station_id === $station->id && $site->company_id === $company->id && $station->vendor_id === $company->owner_id && $charger->ocpp_protocol === $b->protocol && User::whereKey($company->owner_id)->where('role', 'vendor')->exists() && DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->count() === 1 && DB::table('csms_bindings')->where('identifier', $b->identifier)->count() === 1, 409);

        return [$b, $company->id, $company->owner_id];
    }

    private function graph(string $query, array $variables): array
    {
        $reply = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), compact('query', 'variables'));
        $data = $reply->json('data');
        abort_unless($reply->successful() && ! $reply->json('errors') && is_array($data), 503);

        return $data;
    }

    public function observe(int $id): array
    {
        abort_unless(config('csms.fault_worker_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']) && strlen((string) config('csms.graphql_secret')) >= 32, 503);
        [$b, $companyId, $ownerId] = $this->scope($id);
        $generation = FaultMonitor::generation($b);
        $cursor = DB::table('charging_fault_cursors')->where('source_generation', $generation)->first();
        $after = (int) ($cursor->last_event_id ?? 0);
        try {
            $source = $this->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id protocol}}', ['name' => $b->identifier])['ChargingStations'] ?? null;
            abort_unless(is_array($source) && count($source) <= 1, 409);
            $sourceId = $source[0]['id'] ?? null;
            if ($sourceId !== null) {
                abort_unless(is_int($sourceId) && $sourceId > 0 && in_array($source[0]['protocol'] ?? null, [$b->protocol, 'ocpp'.$b->protocol], true) && (! $cursor?->source_station_id || $cursor->source_station_id === $sourceId), 409);
            } else {
                abort_unless(! $cursor?->source_station_id, 409);
            }
            $events = [];
            if ($sourceId !== null) {
                $data = $this->graph('query($station:Int!,$after:Int!){StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station},id:{_gt:$after}},order_by:{id:asc},limit:101){id connectorId evseId connectorStatus errorCode timestamp} newest:StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:1){id}}', ['station' => $sourceId, 'after' => $after]);
                $events = $data['StatusNotifications'] ?? null;
                $newest = $data['newest'] ?? null;
                abort_unless(is_array($events) && count($events) <= 101 && is_array($newest) && count($newest) <= 1 && ($after === 0 || (is_int($newest[0]['id'] ?? null) && $newest[0]['id'] >= $after)), 503);
                $previous = $after;
                foreach ($events as $event) {
                    abort_unless(is_array($event) && is_int($event['id'] ?? null) && $event['id'] > $previous && $event['id'] <= 2147483647 && is_string($event['connectorStatus'] ?? null) && strlen($event['connectorStatus']) <= 40 && is_string($event['timestamp'] ?? null) && strlen($event['timestamp']) <= 50 && (! isset($event['errorCode']) || (is_string($event['errorCode']) && strlen($event['errorCode']) <= 80)), 503);
                    abort_unless(CarbonImmutable::parse($event['timestamp'])->lessThanOrEqualTo(now()), 503);
                    $previous = $event['id'];
                }
            }
            $state = $sourceId === null ? 'not_observed' : (count($events) > 100 ? 'catching_up' : 'caught_up');
            $events = array_slice($events, 0, 100);

            return $this->commit($b, $companyId, $ownerId, $after, function ($current) use ($generation, $sourceId, $events, $state, $after) {
                $created = app(FaultMonitor::class)->ingest($current, ['source_generation' => $generation, 'events' => $events]);
                $last = $events ? end($events)['id'] : $after;
                DB::table('charging_fault_cursors')->updateOrInsert(['source_generation' => $generation], ['binding_id' => $current->id, 'source_station_id' => $sourceId, 'last_event_id' => $last, 'state' => $state, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

                return ['binding_id' => $current->id, 'events' => count($events), 'alerts_created' => $created, 'state' => $state, 'commands_sent' => false, 'capacity_changed' => false];
            });
        } catch (Throwable $e) {
            // Current ownership and unchanged cursor are required even to record a failure.
            $this->commit($b, $companyId, $ownerId, $after, function ($current) use ($generation, $after, $cursor) {
                DB::table('charging_fault_cursors')->updateOrInsert(['source_generation' => $generation], ['binding_id' => $current->id, 'source_station_id' => $cursor->source_station_id ?? null, 'last_event_id' => $after, 'state' => 'requires_review', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

                return [];
            });
            throw $e;
        }
    }

    private function commit(object $original, int $companyId, int $ownerId, int $after, callable $callback): array
    {
        return DB::transaction(function () use ($original, $companyId, $ownerId, $after, $callback) {
            VendorCompany::whereKey($companyId)->lockForUpdate()->firstOrFail();
            DB::table('csms_bindings')->where('id', $original->id)->lockForUpdate()->firstOrFail();
            [$current, $company, $owner] = $this->scope($original->id);
            abort_unless($company === $companyId && $owner === $ownerId && hash_equals(FaultMonitor::generation($original), FaultMonitor::generation($current)), 409);
            $cursor = DB::table('charging_fault_cursors')->where('source_generation', FaultMonitor::generation($current))->lockForUpdate()->first();
            abort_unless((int) ($cursor->last_event_id ?? 0) === $after, 409);

            return $callback($current);
        }, 3);
    }
}
