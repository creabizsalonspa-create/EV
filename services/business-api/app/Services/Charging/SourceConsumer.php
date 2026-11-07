<?php

namespace App\Services\Charging;

use App\Http\Controllers\CsmsSessionController;
use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\RiderAccessToken;
use App\Models\Charging\SessionLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Restartable local-simulator observer. Never dispatches or retries commands. */
class SourceConsumer
{
    private bool $localInbox = false;

    private function graph(string $query, array $variables): array
    {
        $key = (string) config('csms.graphql_secret');
        if (strlen($key) < 32) {
            throw new RuntimeException('Source authentication is not configured.');
        }
        $response = Http::timeout(15)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])
            ->post(config('csms.graphql_url'), compact('query', 'variables'));
        if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
            throw new RuntimeException('Authenticated source query failed.');
        }

        return $response->json('data');
    }

    public static function wh(mixed $value, string $unit, int $multiplier = 0): int
    {
        $text = (string) $value;
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        if ($multiplier !== 0 || ! in_array($unit, ['Wh', 'kWh'], true) || ! preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,3}))?$/D', $text, $parts)) {
            throw new RuntimeException('Unsupported source meter precision or unit.');
        }
        $fraction = $parts[2] ?? '';
        if ($unit === 'Wh' && $fraction !== '') {
            throw new RuntimeException('Fractional Wh requires reconciliation.');
        }
        $result = $unit === 'Wh' ? (int) $parts[1] : (int) $parts[1] * 1000 + (int) str_pad($fraction, 3, '0');
        if ($result > 9000000000000) {
            throw new RuntimeException('Source meter exceeds the safety bound.');
        }

        return $result;
    }

    private function send(SessionLink $link, array $tx, string $type, int $sequence, int $meter, string $at): void
    {
        $key = (string) config('csms.event_secret');
        if (strlen($key) < 32) {
            throw new RuntimeException('Inbox authentication is not configured.');
        }
        $payload = ['event_id' => 'source-'.hash('sha256', implode('|', [$link->id, $tx['transactionId'], $type, $sequence])),
            'session_id' => $link->charging_session_id, 'identifier' => $link->identifier, 'protocol' => $link->protocol,
            'transaction_id' => (string) $tx['transactionId'], 'type' => $type, 'sequence' => $sequence, 'meter_wh' => $meter, 'occurred_at' => $at];
        if ($link->connector_mapping_id) {
            $map = ConnectorMapping::findOrFail($link->connector_mapping_id);
            $payload['source_connector_number'] = $map->source_connector_number;
            $payload['source_evse_number'] = Evse::findOrFail($map->evse_id)->source_evse_number;
        }
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        if ($this->localInbox) {
            // Preserve signed authentication, payload validation and the named
            // inbox throttle without making a nested HTTP call into this worker.
            $request = Request::create('/api/internal/csms/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_EV_TIMESTAMP' => $timestamp, 'HTTP_X_EV_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$raw, $key)], $raw);
            $result = app(ThrottleRequests::class)->handle($request, fn ($r) => app(CsmsSessionController::class)->event($r, app(SessionBridge::class)), 'csms-events');
            if (! $result->isSuccessful()) {
                throw new RuntimeException('Signed local inbox rejected source event.');
            }
        } else {
            $response = Http::timeout(15)->withoutRedirecting()->withHeaders(['Accept' => 'application/json', 'X-EV-Timestamp' => $timestamp, 'X-EV-Signature' => hash_hmac('sha256', $timestamp.'.'.$raw, $key)])
                ->withBody($raw, 'application/json')->post('http://127.0.0.1:8000/api/internal/csms/events');
            if (! $response->successful()) {
                throw new RuntimeException('Signed inbox rejected source event ('.$response->status().').');
            }
        }
        // Inbox commit owns the durable cursor; never advance it on HTTP failure.
        $link->refresh();
    }

    public function consume(SessionLink $link, bool $localInbox = false): int
    {
        $this->localInbox = $localInbox;
        app(SessionBridge::class)->enabled();
        $link->refresh();
        if ($link->closed_without_dispatch_at !== null) {
            return 0;
        }
        if (! preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $link->identifier) || ! in_array($link->protocol, ['1.6', '2.0.1'], true)) {
            throw new RuntimeException('Only provisioned local simulators are supported.');
        }
        $rider = $link->rider_token_id ? RiderAccessToken::findOrFail($link->rider_token_id) : null;
        // Revocation blocks new authorization, not observation/finalization of an
        // already admitted session. Retain the encrypted original source identity.
        $tag = $rider ? Crypt::decryptString($rider->token_ciphertext) : 'EV-SIM-'.$link->charging_session_id;
        if ($link->remote_token_digest) {
            $command = ChargerCommand::findOrFail($link->start_command_id);
            $tag = $command->payload['id_token'] ?? '';
            if (! is_string($tag) || ! hash_equals($link->remote_token_digest, hash('sha256', $tag))) {
                throw new RuntimeException('Remote source token lineage conflict.');
            }
        }
        $identity = $this->graph('query($station:String!,$tag:citext!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$station}},limit:2){id} Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id}}', ['station' => $link->identifier, 'tag' => $tag]);
        if (count($identity['ChargingStations']) > 1 || count($identity['Authorizations']) > 1) {
            throw new RuntimeException('Ambiguous source identity requires reconciliation.');
        }
        if (! $identity['ChargingStations'] || ! $identity['Authorizations']) {
            return 0;
        }
        if ($link->remote_token_digest && (int) $identity['Authorizations'][0]['id'] !== (int) $link->remote_authorization_id) {
            throw new RuntimeException('Remote source registration changed; reconciliation is required.');
        }
        if ($rider && (int) $identity['Authorizations'][0]['id'] !== (int) $rider->source_authorization_id) {
            throw new RuntimeException('Rider source registration changed; reconciliation is required.');
        }
        if ($rider) {
            $records = $this->graph('query($station:Int!,$auth:Int!,$after:timestamptz!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},authorizationId:{_eq:$auth},startTime:{_gte:$after}},order_by:{id:desc},limit:25){id transactionId remoteStartId meterStart startTime endTime isActive totalKwh}}', ['station' => $identity['ChargingStations'][0]['id'], 'auth' => $identity['Authorizations'][0]['id'], 'after' => $link->created_at->toImmutable()->subSeconds(2)->toISOString()])['Transactions'];
            $assigned = SessionLink::where('binding_id', $link->binding_id)->where('id', '!=', $link->id)->whereNotNull('transaction_id')->pluck('transaction_id')->map(fn ($id) => (string) $id)->all();
            $records = array_values(array_filter($records, fn ($tx) => ! in_array((string) $tx['transactionId'], $assigned, true)));
        } else {
            $records = $this->graph('query($station:Int!,$auth:Int!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},authorizationId:{_eq:$auth}},limit:2){id transactionId remoteStartId meterStart startTime endTime isActive totalKwh}}', ['station' => $identity['ChargingStations'][0]['id'], 'auth' => $identity['Authorizations'][0]['id']])['Transactions'];
        }
        if (count($records) > 1) {
            throw new RuntimeException('Multiple source transactions require reconciliation.');
        }
        if (! $records || $records[0]['meterStart'] === null || ! $records[0]['startTime']) {
            return 0;
        }
        $tx = $records[0];
        if (($link->transaction_id !== null && $link->transaction_id !== (string) $tx['transactionId']) ||
            ($link->protocol === '2.0.1' && (int) $tx['remoteStartId'] !== (int) $link->start_command_id) ||
            CarbonImmutable::parse($tx['startTime'])->lt($link->created_at->subSeconds(2))) {
            throw new RuntimeException('Source correlation conflict requires reconciliation.');
        }
        if ($link->connector_mapping_id) {
            $map = ConnectorMapping::findOrFail($link->connector_mapping_id);
            $evse = Evse::findOrFail($map->evse_id);
            if ($evse->binding_id !== $link->binding_id) {
                throw new RuntimeException('Mapped source identity conflict.');
            }
            if ($link->protocol === '1.6') {
                $proof = $this->graph('query($tx:Int!){Transactions(where:{id:{_eq:$tx},tenantId:{_eq:1}}){connectorId}}', ['tx' => $tx['id']])['Transactions'];
                $connector = $this->graph('query($id:Int!,$station:Int!){Connectors(where:{id:{_eq:$id},tenantId:{_eq:1},stationId:{_eq:$station}}){connectorId}}', ['id' => $proof[0]['connectorId'] ?? 0, 'station' => $identity['ChargingStations'][0]['id']])['Connectors'];
                if (count($connector) !== 1 || (int) $connector[0]['connectorId'] !== $map->source_connector_number) {
                    throw new RuntimeException('Source connector mismatch.');
                }
            } else {
                // CitrineOS 2.1 normalized transaction/EVSE projections do not retain
                // protocol EVSE numbers. Verify the authenticated persisted Started call.
                $info = ['transactionId' => (string) $tx['transactionId']];
                if (! $rider) {
                    $info['remoteStartId'] = (int) $link->start_command_id;
                }
                $proof = $this->graph('query($station:Int!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},type:{_eq:2},action:{_eq:"TransactionEvent"},payload:{_contains:$match}},limit:2){payload}}', ['station' => $identity['ChargingStations'][0]['id'], 'match' => ['eventType' => 'Started', 'transactionInfo' => $info]])['OCPPMessages'];
                if (! $proof) {
                    throw new RuntimeException('Source target proof is unavailable.');
                }
                foreach ($proof as $row) {
                    if (($row['payload']['evse']['id'] ?? null) !== $evse->source_evse_number || ($row['payload']['evse']['connectorId'] ?? null) !== $map->source_connector_number) {
                        throw new RuntimeException('Source EVSE/connector mismatch.');
                    }
                }
            }
        }
        $count = 0;
        $baseline = self::wh($tx['meterStart'], 'kWh');
        if ($link->transaction_id === null) {
            $this->send($link, $tx, 'started', 0, $baseline, $tx['startTime']);
            $count++;
        }
        $rows = $this->graph('query($tx:Int!,$after:Int!){MeterValues(where:{tenantId:{_eq:1},transactionDatabaseId:{_eq:$tx},id:{_gt:$after}},order_by:{id:asc},limit:100){id timestamp sampledValue}}', ['tx' => $tx['id'], 'after' => max(0, $link->source_meter_cursor)])['MeterValues'];
        foreach ($rows as $row) {
            $samples = array_values(array_filter($row['sampledValue'], fn ($s) => ($s['measurand'] ?? '') === 'Energy.Active.Import.Register' && empty($s['phase'])));
            if (count($samples) > 1) {
                throw new RuntimeException('Ambiguous register requires reconciliation.');
            }
            if (! $samples || ($samples[0]['context'] ?? '') === 'Transaction.Begin') {
                $link->update(['source_meter_cursor' => $row['id']]);

                continue;
            }
            $sample = $samples[0];
            $meter = self::wh($sample['value'], $sample['unitOfMeasure']['unit'] ?? '', $sample['unitOfMeasure']['multiplier'] ?? 0);
            $ended = ($sample['context'] ?? '') === 'Transaction.End';
            if ($ended && ($tx['isActive'] || ! $tx['endTime'] || $tx['totalKwh'] === null || $meter - $baseline !== self::wh($tx['totalKwh'], 'kWh'))) {
                // Upstream final energy and end flags converge asynchronously.
                return $count;
            }
            if ($row['id'] > 1000000000) {
                throw new RuntimeException('Source sequence exceeds the inbox bound.');
            }
            $this->send($link, $tx, $ended ? 'ended' : 'meter', $row['id'], $meter, $ended ? $tx['endTime'] : $row['timestamp']);
            $link->update(['source_meter_cursor' => $row['id']]);
            $count++;
            if ($ended) {
                break;
            }
        }

        return $count;
    }
}
