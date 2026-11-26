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
        // The checked source may be returned to the operator inbox. Never
        // publish a source observation after ownership, country or account
        // authority has changed (including between the HTTP read and commit).
        abort_unless($charger->source === 'simulator' && $station->source === 'simulator'
            && $site->station_id === $station->id && $site->company_id === $company->id
            && $charger->company_id === $company->id
            && $station->vendor_id === $company->owner_id
            && $station->country === $site->country && $site->country === $company->country
            && $charger->ocpp_protocol === $b->protocol
            && User::whereKey($company->owner_id)->where('role', 'vendor')
                ->where('account_status', 'active')->exists()
            && DB::table('csms_bindings')->where('vendor_charger_id', $charger->id)->count() === 1
            && DB::table('csms_bindings')->where('identifier', $b->identifier)->count() === 1, 409);

        return [$b, $company->id, $company->owner_id];
    }

    private function requireSource(string $url, string $key): void
    {
        abort_unless(config('csms.fault_worker_enabled') && config('csms.session_bridge_enabled')
            && app()->environment(['local', 'testing'])
            && app(CsmsGraphqlTransport::class)->requireApproved() === $url
            && hash_equals($key, (string) config('csms.graphql_secret')), 503,
            'Fault source authority changed during observation.');
    }

    private function graph(string $query, array $variables, string $url, string $key): array
    {
        $this->requireSource($url, $key);
        $reply = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])->post($url, compact('query', 'variables'));
        $this->requireSource($url, $key);
        $data = $reply->json('data');
        abort_unless($reply->successful() && ! $reply->json('errors') && is_array($data), 503);

        return $data;
    }

    public function observe(int $id): array
    {
        abort_unless(config('csms.fault_worker_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']) && strlen((string) config('csms.graphql_secret')) >= 32, 503);
        // Reject an unreviewed plaintext/credential-bearing source URL before
        // any privileged GraphQL request or local fault cursor mutation.
        $url = app(CsmsGraphqlTransport::class)->requireApproved();
        $key = (string) config('csms.graphql_secret');
        [$b, $companyId, $ownerId] = $this->scope($id);
        $generation = FaultMonitor::generation($b);
        $cursor = DB::table('charging_fault_cursors')->where('source_generation', $generation)->first();
        $after = (int) ($cursor->last_event_id ?? 0);
        try {
            // The last committed event is the source-clock boundary even when
            // a previous page contained only Available/diagnostic statuses.
            // An absent durable observation cannot certify a source cursor.
            $previousClock = null;
            $lastClock = null;
            $sameTimeTargets = [];
            if ($after > 0) {
                $lastClock = DB::table('charging_status_observations')
                    ->where('source_generation', $generation)
                    ->where('source_event_id', $after)
                    ->value('occurred_at');
                abort_unless(is_string($lastClock), 503, 'Status source cursor lacks durable clock evidence.');
                $previousClock = CarbonImmutable::parse($lastClock, 'UTC');
            }
            $source = $this->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id protocol}}', ['name' => $b->identifier], $url, $key)['ChargingStations'] ?? null;
            abort_unless(is_array($source) && array_is_list($source) && count($source) <= 1, 503, 'Malformed source station projection.');
            $sourceId = $source[0]['id'] ?? null;
            if ($source) {
                // A malformed row is not an absent charger. Treating missing
                // or non-numeric ids as a healthy empty poll can hide faults.
                abort_unless(is_int($sourceId) && $sourceId > 0, 503, 'Malformed source station row.');
                abort_unless(in_array($source[0]['protocol'] ?? null, [$b->protocol, 'ocpp'.$b->protocol], true) && (! $cursor?->source_station_id || $cursor->source_station_id === $sourceId), 409);
            } else {
                abort_unless(! $cursor?->source_station_id, 409);
            }
            $events = [];
            if ($sourceId !== null) {
                $data = $this->graph('query($station:Int!,$after:Int!){StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station},id:{_gt:$after}},order_by:{id:asc},limit:101){id connectorId evseId connectorStatus errorCode timestamp} newest:StatusNotifications(where:{tenantId:{_eq:1},stationId:{_eq:$station}},order_by:{id:desc},limit:1){id}}', ['station' => $sourceId, 'after' => $after], $url, $key);
                $events = $data['StatusNotifications'] ?? null;
                $newest = $data['newest'] ?? null;
                abort_unless(
                    is_array($events) && array_is_list($events) && count($events) <= 101 &&
                    is_array($newest) && array_is_list($newest) && count($newest) <= 1 &&
                    (! $newest || (is_array($newest[0]) && is_int($newest[0]['id'] ?? null) && $newest[0]['id'] >= 0)) &&
                    ($after === 0 || (is_int($newest[0]['id'] ?? null) && $newest[0]['id'] >= $after)),
                    503,
                    'Malformed source status page or newest-event projection.'
                );
                // An empty page cannot mean caught_up when this same
                // source reports events newer than the previous cursor.
                // Leave the cursor unchanged and raise a review incident.
                if (! $events && $newest) {
                    abort_unless(
                        is_int($newest[0]['id'] ?? null) &&
                        $newest[0]['id'] <= $after,
                        503,
                        'Source reported newer events but returned an empty page.'
                    );
                }
                $previous = $after;
                foreach ($events as $event) {
                    abort_unless(is_array($event) && is_int($event['id'] ?? null) && $event['id'] > $previous && $event['id'] <= 2147483647 && is_string($event['connectorStatus'] ?? null) && strlen($event['connectorStatus']) <= 40 && is_string($event['timestamp'] ?? null) && strlen($event['timestamp']) <= 50 && (! isset($event['errorCode']) || (is_string($event['errorCode']) && strlen($event['errorCode']) <= 80)), 503);
                    FaultMonitor::requireProtocolReceipt($b->protocol, $event);
                    try {
                        // Use the same strict absolute-time contract as the
                        // persisted fault observer, including fractional future
                        // instants. Permissive date parsing can silently convert
                        // invalid source text into an invented observation.
                        $eventClock = SourceStatusTime::parse($event['timestamp']);
                    } catch (Throwable) {
                        abort(503, 'Source event timestamp is invalid.');
                    }
                    abort_unless($previousClock === null || ! $eventClock->lt($previousClock), 503, 'Source status chronology regressed.');
                    // Equal timestamps may describe distinct connectors, or
                    // repeated identical receipts, but must never establish
                    // two incompatible states for the same source component.
                    // OCPP 1.6 StatusNotification has no EVSE identifier.
                    // The GraphQL projection can explicitly return null for it.
                    // Use the same zero sentinel as FaultMonitor persistence,
                    // never infer a missing field or a 2.0.1 EVSE.
                    $evse = $event['evseId'] ?? null;
                    if ($b->protocol === '1.6' && array_key_exists('evseId', $event) && $evse === null) {
                        $evse = 0;
                    }
                    $connector = $event['connectorId'] ?? null;
                    abort_unless(is_int($evse) && $evse >= 0 && is_int($connector) && $connector >= 0, 503,
                        'Malformed source component identity.');
                    $target = $evse.':'.$connector;
                    $receipt = [$event['connectorStatus'], $event['errorCode'] ?? null];
                    if ($previousClock === null || ! $eventClock->equalTo($previousClock)) {
                        $sameTimeTargets = [];
                    } elseif (isset($sameTimeTargets[$target])) {
                        abort_unless($sameTimeTargets[$target] === $receipt, 503,
                            'Conflicting same-time source status receipts.');
                    }
                    if ($lastClock !== null && $eventClock->equalTo(CarbonImmutable::parse($lastClock, 'UTC'))) {
                        $conflict = DB::table('charging_status_observations')
                            ->where('source_generation', $generation)
                            ->where('source_event_id', '<=', $after)
                            ->where('occurred_at', $lastClock)
                            ->where('evse_number', $evse)->where('connector_number', $connector)
                            ->where(function ($q) use ($receipt) {
                                $q->where('status', '!=', $receipt[0]);
                                if ($receipt[1] === null) {
                                    $q->orWhereNotNull('error_code');
                                } else {
                                    $q->orWhereNull('error_code')->orWhere('error_code', '!=', $receipt[1]);
                                }
                            })->exists();
                        abort_if($conflict, 503, 'Conflicting same-time status crosses the durable cursor.');
                    }
                    $sameTimeTargets[$target] = $receipt;
                    $previousClock = $eventClock;
                    $previous = $event['id'];
                }
                // The highest event in a source page cannot exceed the
                // source's newest observed event. The initial cursor (0)
                // must be validated too, otherwise a contradictory snapshot
                // can be committed as if it were healthy.
                if ($events) {
                    abort_unless(
                        is_int($newest[0]['id'] ?? null) &&
                        $newest[0]['id'] >= $previous,
                        503,
                        'Source event page exceeds reported newest event.'
                    );
                }
            }
            // Status is caught up only if the exact last durable source row
            // reaches the latest upstream watermark. A short page can still
            // lag a newer event (e.g. concurrent source commits).
            $lastRead = $events ? $events[min(count($events), 100) - 1]['id'] : $after;
            $state = $sourceId === null ? 'not_observed' :
                (count($events) > 100 || ($newest && $newest[0]['id'] > $lastRead) ? 'catching_up' : 'caught_up');
            $events = array_slice($events, 0, 100);

            return $this->commit($b, $companyId, $ownerId, $after, function ($current) use ($generation, $sourceId, $events, $state, $after, $ownerId, $companyId, $url, $key) {
                $this->requireSource($url, $key);
                // The source observation, fault alert and vendor-only in-app
                // notification commit atomically under the verified ownership lock.
                // Historical replay never emits a second notification.
                $created = app(FaultMonitor::class)->ingestForOwner(
                    $current,
                    ['source_generation' => $generation, 'events' => $events],
                    $companyId,
                    $ownerId,
                );
                // A previous monitor review state has cleared only when an
                // authenticated source page catches up, never on a mere poll.
                $previous = DB::table('charging_fault_cursors')
                    ->where('source_generation', $generation)->lockForUpdate()->first();
                if ($previous && $previous->state === 'requires_review' && $state === 'caught_up') {
                    InboxNotification::create([
                        'user_id' => $ownerId,
                        'kind' => 'charging.monitor.resumed.simulated',
                        'metadata' => [
                            'source' => 'simulator',
                            'evidence' => 'SIMULATED_SOURCE_OBSERVED',
                            'station_id' => $current->station_id,
                            'company_id' => $companyId,
                            'charger_id' => $current->vendor_charger_id,
                            'physical_verification' => false,
                        ],
                    ]);
                }
                $last = $events ? end($events)['id'] : $after;
                DB::table('charging_fault_cursors')->updateOrInsert(['source_generation' => $generation], ['binding_id' => $current->id, 'source_station_id' => $sourceId, 'last_event_id' => $last, 'state' => $state, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

                return ['binding_id' => $current->id, 'events' => count($events), 'alerts_created' => $created, 'state' => $state, 'commands_sent' => false, 'capacity_changed' => false];
            });
        } catch (Throwable $e) {
            // Current ownership and unchanged cursor are required even to record a failure.
            $this->commit($b, $companyId, $ownerId, $after, function ($current) use ($generation, $after, $cursor, $ownerId, $companyId) {
                $previous = DB::table('charging_fault_cursors')
                    ->where('source_generation', $generation)->lockForUpdate()->first();
                if (! $previous || $previous->state !== 'requires_review') {
                    InboxNotification::create([
                        'user_id' => $ownerId,
                        'kind' => 'charging.monitor.requires_review.simulated',
                        'metadata' => [
                            'source' => 'simulator',
                            // The page failed validation or was unavailable:
                            // no event-source observation is established here.
                            'evidence' => 'SIMULATED_SOURCE_UNVERIFIED',
                            'station_id' => $current->station_id,
                            'company_id' => $companyId,
                            'charger_id' => $current->vendor_charger_id,
                            'physical_verification' => false,
                        ],
                    ]);
                }
                DB::table('charging_fault_cursors')->updateOrInsert(
                    ['source_generation' => $generation],
                    [
                        'binding_id' => $current->id,
                        'source_station_id' => $cursor->source_station_id ?? null,
                        'last_event_id' => $after,
                        'state' => 'requires_review',
                        'checked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

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
