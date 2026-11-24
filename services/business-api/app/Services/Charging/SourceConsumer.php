<?php

namespace App\Services\Charging;

use App\Http\Controllers\CsmsSessionController;
use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Charging\RiderAccessToken;
use App\Models\Charging\SessionLink;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Restartable local-simulator observer. Never dispatches or retries commands. */
class SourceConsumer
{
    private bool $localInbox = false;

    /** A poll must not splice station, transaction or meter evidence from different authorities. */
    private function requireAuthority(array $authority): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('csms.session_bridge_enabled')
            || config('csms.graphql_url') !== $authority[0]
            || ! hash_equals($authority[1], (string) config('csms.graphql_secret'))
            || ! hash_equals($authority[2], (string) config('csms.event_secret'))) {
            throw new RuntimeException('Source authority changed; reconciliation is required.');
        }
    }

    private function graph(string $query, array $variables, ?array $authority = null): array
    {
        if ($authority !== null) {
            $this->requireAuthority($authority);
        }
        $key = $authority[1] ?? (string) config('csms.graphql_secret');
        if (strlen($key) < 32) {
            throw new RuntimeException('Source authentication is not configured.');
        }
        $url = $authority[0] ?? config('csms.graphql_url');
        if (! app(CsmsGraphqlTransport::class)->approved($url)) {
            throw new RuntimeException('Authenticated CSMS source transport is not approved.');
        }
        $response = Http::timeout(15)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])
            ->post($url, compact('query', 'variables'));
        if ($authority !== null) {
            $this->requireAuthority($authority);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Authenticated source query failed.');
        }
        $projection = SourceGraphJson::decode($response->body());
        if (! empty($projection['errors']) || ! is_array($projection['data'] ?? null)) {
            throw new RuntimeException('Authenticated source query failed.');
        }

        return $projection['data'];
    }

    public static function wh(mixed $value, string $unit, mixed $multiplier = 0): int
    {
        // Decimal energy values must be original text or exact integers.
        // JSON booleans cast to "1" and floating-point conversions may
        // silently manufacture billable Wh: never accept either as evidence.
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('Unsupported source meter scalar type.');
        }
        $text = (string) $value;
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        if (! is_int($multiplier) || $multiplier !== 0 || ! in_array($unit, ['Wh', 'kWh'], true) || ! preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,3}))?$/D', $text, $parts)) {
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

    private static function registerWh(array $sample): int
    {
        $units = $sample['unitOfMeasure'] ?? null;
        if (! is_array($units) || ! is_string($units['unit'] ?? null)) {
            throw new RuntimeException('Unsupported source meter unit projection.');
        }

        return self::wh($sample['value'] ?? null, $units['unit'],
            array_key_exists('multiplier', $units) ? $units['multiplier'] : 0);
    }

    private function target(SessionLink $link): array
    {
        $binding = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
        if (! $binding || $binding->identifier !== $link->identifier || $binding->protocol !== $link->protocol) {
            throw new RuntimeException('Mapped source binding changed; reconciliation is required.');
        }
        $target = ['binding' => [$binding->id, $binding->station_id, $binding->vendor_charger_id,
            $binding->identifier, $binding->protocol], 'mapping' => null];
        if ($link->connector_mapping_id !== null) {
            $map = ConnectorMapping::findOrFail($link->connector_mapping_id);
            $evse = Evse::findOrFail($map->evse_id);
            if ($evse->binding_id !== $binding->id || $map->station_id !== $binding->station_id
                || $evse->charger_id !== $binding->vendor_charger_id || $evse->mode !== 'csms') {
                throw new RuntimeException('Mapped source identity conflict.');
            }
            $target['mapping'] = [$map->id, $map->evse_id, $map->connector_id,
                $map->source_connector_number, $evse->source_evse_number];
        }

        return $target;
    }

    private function requireTarget(SessionLink $link, array $target): void
    {
        $current = SessionLink::findOrFail($link->id);
        if ($current->charging_session_id !== $link->charging_session_id
            || $current->closed_without_dispatch_at !== null
            || $this->target($current) !== $target) {
            throw new RuntimeException('Source target changed; reconciliation is required.');
        }
    }

    private function send(SessionLink $link, array $tx, string $type, int $sequence, int $meter, string $at, array $authority, array $target): void
    {
        $this->requireAuthority($authority);
        $this->requireTarget($link, $target);
        $key = $authority[2];
        if (strlen($key) < 32) {
            throw new RuntimeException('Inbox authentication is not configured.');
        }
        $payload = ['event_id' => 'source-'.hash('sha256', implode('|', [$link->id, $tx['transactionId'], $type, $sequence])),
            'session_id' => $link->charging_session_id, 'identifier' => $link->identifier, 'protocol' => $link->protocol,
            'transaction_id' => (string) $tx['transactionId'], 'type' => $type, 'sequence' => $sequence, 'meter_wh' => $meter, 'occurred_at' => $at];
        if ($target['mapping'] !== null) {
            $payload['source_connector_number'] = $target['mapping'][3];
            $payload['source_evse_number'] = $target['mapping'][4];
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

    private function advanceCursor(SessionLink $link, int $sourceRowId, string $sourceAt, array $authority, array $target): void
    {
        $this->requireAuthority($authority);
        $this->requireTarget($link, $target);
        // A second observer may have already committed a later page while this
        // worker was reading source rows. Never rewind its durable progress.
        // The signed inbox independently rejects replayed or conflicting
        // events, so this update does not invent transaction authority.
        SessionLink::whereKey($link->id)
            ->where('source_meter_cursor', '<', $sourceRowId)
            ->update([
                'source_meter_cursor' => $sourceRowId,
                'source_meter_cursor_at' => SourceStatusTime::parse($sourceAt, 300)->utc()->format('Y-m-d H:i:s.u'),
            ]);
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
        if ($link->ended_at !== null && $link->source_meter_cursor >= $link->last_sequence) {
            // Terminal inbox commit and source cursor are both durable. Only
            // then can late telemetry be ignored. If the terminal inbox ACK
            // was lost before cursor persistence, replay its existing signed
            // event identity to reconcile the cursor without rebilling.
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
        $target = $this->target($link);
        $authority = [config('csms.graphql_url'), (string) config('csms.graphql_secret'), (string) config('csms.event_secret')];
        $this->requireAuthority($authority);
        if (strlen($authority[2]) < 32) {
            throw new RuntimeException('Inbox authentication is not configured.');
        }
        $identity = $this->graph('query($station:String!,$tag:citext!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$station}},limit:2){id protocol} Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id}}', ['station' => $link->identifier, 'tag' => $tag], $authority);
        if (! is_array($identity['ChargingStations'] ?? null) || ! array_is_list($identity['ChargingStations']) ||
            ! is_array($identity['Authorizations'] ?? null) || ! array_is_list($identity['Authorizations'])) {
            throw new RuntimeException('Malformed authenticated source identity projection requires reconciliation.');
        }
        if (count($identity['ChargingStations']) > 1 || count($identity['Authorizations']) > 1) {
            throw new RuntimeException('Ambiguous source identity requires reconciliation.');
        }
        if (! $identity['ChargingStations'] || ! $identity['Authorizations']) {
            // Provisioning can still be pending before the first signed start.
            // Losing an authenticated station or authorization after that is
            // an incident, not evidence that the running session completed.
            if ($link->transaction_id !== null || $link->source_station_id !== null) {
                throw new RuntimeException('Started source identity disappeared; reconciliation is required.');
            }

            return 0;
        }
        if (! is_int($identity['Authorizations'][0]['id'] ?? null) || $identity['Authorizations'][0]['id'] <= 0) {
            throw new RuntimeException('Invalid source authorization identity requires reconciliation.');
        }
        // The source station, not the business binding alone, establishes the
        // protocol of the observed transaction. A moved/reprovisioned charger
        // must never advance a session or its invoice under the old version.
        $sourceProtocol = $identity['ChargingStations'][0]['protocol'] ?? null;
        if (! is_string($sourceProtocol) || ! in_array($sourceProtocol, [$link->protocol, 'ocpp'.$link->protocol], true)) {
            throw new RuntimeException('Source station protocol mismatch requires reconciliation.');
        }
        $sourceStationId = $identity['ChargingStations'][0]['id'] ?? null;
        if (! is_int($sourceStationId) || $sourceStationId <= 0 ||
            ($link->source_station_id !== null && (int) $link->source_station_id !== $sourceStationId)) {
            throw new RuntimeException('Source station identity changed; reconciliation is required.');
        }
        if ($link->source_station_id === null && $link->transaction_id !== null) {
            // Pre-migration sessions were never source-station pinned. Their
            // upstream history must be reviewed rather than auto-adopted.
            throw new RuntimeException('Legacy source station identity requires reconciliation.');
        }
        if ($link->remote_token_digest && (int) $identity['Authorizations'][0]['id'] !== (int) $link->remote_authorization_id) {
            throw new RuntimeException('Remote source registration changed; reconciliation is required.');
        }
        if ($rider && (int) $identity['Authorizations'][0]['id'] !== (int) $rider->source_authorization_id) {
            throw new RuntimeException('Rider source registration changed; reconciliation is required.');
        }
        if ($rider) {
            $records = $this->graph('query($station:Int!,$auth:Int!,$after:timestamptz!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},authorizationId:{_eq:$auth},startTime:{_gte:$after}},order_by:{id:desc},limit:25){id transactionId remoteStartId meterStart startTime endTime isActive totalKwh}}', ['station' => $identity['ChargingStations'][0]['id'], 'auth' => $identity['Authorizations'][0]['id'], 'after' => $link->created_at->toImmutable()->subSeconds(2)->toISOString()], $authority)['Transactions'] ?? null;
        } else {
            $records = $this->graph('query($station:Int!,$auth:Int!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},authorizationId:{_eq:$auth}},limit:2){id transactionId remoteStartId meterStart startTime endTime isActive totalKwh}}', ['station' => $identity['ChargingStations'][0]['id'], 'auth' => $identity['Authorizations'][0]['id']], $authority)['Transactions'] ?? null;
        }
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException('Malformed authenticated source transaction projection requires reconciliation.');
        }
        // Validate every raw row before filtering already-owned rider
        // transactions. A malformed earlier row must not be silently skipped
        // or trigger a PHP warning while examining its transactionId.
        $required = ['id', 'transactionId', 'remoteStartId', 'meterStart', 'startTime', 'endTime', 'isActive', 'totalKwh'];
        foreach ($records as $candidate) {
            if (! is_array($candidate) || array_diff($required, array_keys($candidate)) !== [] ||
                ! is_bool($candidate['isActive']) || ! in_array(gettype($candidate['transactionId']), ['string', 'integer'], true) ||
                (string) $candidate['transactionId'] === '' ||
                // OCPP 1.6 uses a signed 32-bit integer, while 2.0.1 uses
                // a bounded opaque string. Never sign or bill an impossible
                // protocol transaction ID based on generic GraphQL casting.
                ($link->protocol === '1.6' && ! OcppTransactionId::valid16((string) $candidate['transactionId'])) ||
                ($link->protocol === '2.0.1' && (! is_string($candidate['transactionId']) ||
                    strlen($candidate['transactionId']) > 36)) ||
                // Hasura Int identifiers must be real integers, not strings,
                // booleans or arrays that PHP might silently coerce to 1.
                ($candidate['remoteStartId'] !== null && ! is_int($candidate['remoteStartId'])) ||
                // An upstream boolean converts to "1" through a plain cast,
                // making a fabricated one-kWh baseline look billable.
                ($candidate['meterStart'] !== null && (! is_scalar($candidate['meterStart']) || is_bool($candidate['meterStart']))) ||
                ($candidate['startTime'] !== null && ! is_string($candidate['startTime'])) ||
                ($candidate['endTime'] !== null && ! is_string($candidate['endTime'])) ||
                ($candidate['totalKwh'] !== null && (! is_scalar($candidate['totalKwh']) || is_bool($candidate['totalKwh'])))) {
                throw new RuntimeException('Malformed authenticated source transaction row requires reconciliation.');
            }
        }
        if ($rider) {
            $assigned = SessionLink::where('binding_id', $link->binding_id)->where('id', '!=', $link->id)->whereNotNull('transaction_id')->pluck('transaction_id')->map(fn ($id) => (string) $id)->all();
            $records = array_values(array_filter($records, fn ($tx) => ! in_array((string) $tx['transactionId'], $assigned, true)));
        }
        if (count($records) > 1) {
            throw new RuntimeException('Multiple source transactions require reconciliation.');
        }
        if (! $records || $records[0]['meterStart'] === null || ! $records[0]['startTime']) {
            // Initial provisioning can be incomplete. Once source start was
            // signed, disappearing/incomplete transaction evidence must never
            // be mistaken for a healthy empty poll or a completed session.
            if ($link->transaction_id !== null) {
                throw new RuntimeException('Started source transaction missing or incomplete; reconciliation is required.');
            }

            return 0;
        }
        $tx = $records[0];
        $sourceTransactionDatabaseId = $tx['id'] ?? null;
        if (! is_int($sourceTransactionDatabaseId) || $sourceTransactionDatabaseId <= 0 ||
            ($link->source_transaction_database_id !== null && (int) $link->source_transaction_database_id !== $sourceTransactionDatabaseId)) {
            throw new RuntimeException('Authenticated source transaction row identity changed; reconciliation is required.');
        }
        if ($link->source_transaction_database_id === null && $link->transaction_id !== null) {
            throw new RuntimeException('Legacy source transaction row identity requires reconciliation.');
        }
        $startedAt = SourceStatusTime::parse($tx['startTime'], 300);
        $endedAt = $tx['endTime'] === null ? null : SourceStatusTime::parse($tx['endTime'], 300);
        if ($endedAt && $endedAt->lt($startedAt)) {
            throw new RuntimeException('Source transaction end predates its start.');
        }
        if (($link->transaction_id !== null && $link->transaction_id !== (string) $tx['transactionId']) ||
            ($link->protocol === '2.0.1' && (int) $tx['remoteStartId'] !== (int) $link->start_command_id) ||
            $startedAt->lt($link->created_at->subSeconds(2))) {
            throw new RuntimeException('Source correlation conflict requires reconciliation.');
        }
        $this->requireTarget($link, $target);
        if ($link->connector_mapping_id) {
            $map = ConnectorMapping::findOrFail($link->connector_mapping_id);
            $evse = Evse::findOrFail($map->evse_id);
            if ($evse->binding_id !== $link->binding_id) {
                throw new RuntimeException('Mapped source identity conflict.');
            }
            if ($link->protocol === '1.6') {
                $proof = $this->graph('query($tx:Int!){Transactions(where:{id:{_eq:$tx},tenantId:{_eq:1}}){connectorId}}', ['tx' => $tx['id']], $authority)['Transactions'] ?? null;
                // The source graph must return one explicitly typed transaction
                // connector, not a malformed object that silently becomes id 0.
                if (! is_array($proof) || ! array_is_list($proof) || count($proof) !== 1 ||
                    ! is_array($proof[0]) || ! is_int($proof[0]['connectorId'] ?? null) || $proof[0]['connectorId'] <= 0) {
                    throw new RuntimeException('Malformed source transaction connector proof.');
                }
                $connector = $this->graph('query($id:Int!,$station:Int!){Connectors(where:{id:{_eq:$id},tenantId:{_eq:1},stationId:{_eq:$station}}){connectorId}}', ['id' => $proof[0]['connectorId'], 'station' => $identity['ChargingStations'][0]['id']], $authority)['Connectors'] ?? null;
                if (! is_array($connector) || ! array_is_list($connector) || count($connector) !== 1 ||
                    ! is_array($connector[0]) || ! is_int($connector[0]['connectorId'] ?? null) ||
                    $connector[0]['connectorId'] !== (int) $map->source_connector_number) {
                    throw new RuntimeException('Source connector mismatch or malformed mapping proof.');
                }
            } else {
                // CitrineOS 2.1 normalized transaction/EVSE projections do not retain
                // protocol EVSE numbers. Verify the authenticated persisted Started call.
                $info = ['transactionId' => (string) $tx['transactionId']];
                if (! $rider) {
                    $info['remoteStartId'] = (int) $link->start_command_id;
                }
                $proof = $this->graph('query($station:Int!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},type:{_eq:2},action:{_eq:"TransactionEvent"},payload:{_contains:$match}},limit:2){payload}}', ['station' => $identity['ChargingStations'][0]['id'], 'match' => ['eventType' => 'Started', 'transactionInfo' => $info]], $authority)['OCPPMessages'] ?? null;
                if (! is_array($proof) || ! array_is_list($proof) || count($proof) < 1 || count($proof) > 2) {
                    throw new RuntimeException('Malformed source Started message projection.');
                }
                foreach ($proof as $row) {
                    $payload = is_array($row) ? ($row['payload'] ?? null) : null;
                    $reported = is_array($payload) ? ($payload['transactionInfo'] ?? null) : null;
                    if (! is_array($reported) || ($payload['eventType'] ?? null) !== 'Started' ||
                        ($reported['transactionId'] ?? null) !== (string) $tx['transactionId'] ||
                        (! $rider && ($reported['remoteStartId'] ?? null) !== (int) $link->start_command_id) ||
                        ($payload['evse']['id'] ?? null) !== (int) $evse->source_evse_number ||
                        ($payload['evse']['connectorId'] ?? null) !== (int) $map->source_connector_number) {
                        throw new RuntimeException('Source Started target, transaction or EVSE/connector mismatch.');
                    }
                }
            }
        }
        // Verify the baseline and any already-reported total with the exact
        // Wh/kWh policy BEFORE pinning an external row identity. A malformed
        // decimal string is not trustworthy provenance and must not leave an
        // otherwise unstarted business session attached to that source row.
        $baseline = self::wh($tx['meterStart'], 'kWh');
        if ($tx['totalKwh'] !== null) {
            self::wh($tx['totalKwh'], 'kWh');
        }
        // Atomically pin both external row identifiers before the first
        // signed event. A reused protocol transactionId or a reprovisioned
        // station row cannot adopt an existing canonical charging session.
        DB::transaction(function () use ($link, $sourceStationId, $sourceTransactionDatabaseId, $authority, $target): void {
            $this->requireAuthority($authority);
            $this->requireTarget($link, $target);
            $locked = SessionLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if (($locked->source_station_id !== null && (int) $locked->source_station_id !== $sourceStationId) ||
                ($locked->source_transaction_database_id !== null && (int) $locked->source_transaction_database_id !== $sourceTransactionDatabaseId) ||
                (($locked->source_station_id === null || $locked->source_transaction_database_id === null) && $locked->transaction_id !== null)) {
                throw new RuntimeException('Authenticated source row identity requires reconciliation.');
            }
            if (SessionLink::where('binding_id', $locked->binding_id)
                ->where('id', '!=', $locked->id)
                ->where('source_transaction_database_id', $sourceTransactionDatabaseId)->exists()) {
                throw new RuntimeException('Source transaction row is already linked; reconciliation is required.');
            }
            if ($locked->source_station_id === null || $locked->source_transaction_database_id === null) {
                $locked->source_station_id = $sourceStationId;
                $locked->source_transaction_database_id = $sourceTransactionDatabaseId;
                $locked->save();
            }
        });
        $link->refresh();
        $count = 0;
        if ($link->transaction_id === null) {
            $this->send($link, $tx, 'started', 0, $baseline, $tx['startTime'], $authority, $target);
            $count++;
        }
        $rows = $this->graph('query($tx:Int!,$after:Int!){MeterValues(where:{tenantId:{_eq:1},transactionDatabaseId:{_eq:$tx},id:{_gt:$after}},order_by:{id:asc},limit:100){id timestamp sampledValue}}', ['tx' => $tx['id'], 'after' => max(0, $link->source_meter_cursor)], $authority)['MeterValues'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            throw new RuntimeException('Source meter page is missing or exceeds its limit.');
        }
        // Check the complete page before persisting any cursor, including rows
        // that do not contain an energy register. A truncated/reordered source
        // projection must not silently skip the original event after restart.
        $previousId = max(0, $link->source_meter_cursor);
        // An unacknowledged terminal inbox commit has already recorded the
        // source transaction endTime as last_event_at. Its actual terminal
        // meter sample may legitimately precede that endTime; replay must
        // still be checked against the authenticated transaction window.
        // The durable cursor also tracks ignored diagnostic rows. Last signed
        // energy time alone cannot prove cross-page chronology when an earlier
        // page finished with non-energy telemetry. Preserve an older session's
        // limited historical evidence by falling back only when no cursor time
        // was ever stored.
        $previousSourceAt = $link->source_meter_cursor_at ??
            ($link->ended_at === null ? ($link->last_event_at ?? $startedAt) : $startedAt);
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] <= $previousId || $row['id'] > 1000000000 ||
                ! is_string($row['timestamp'] ?? null) || ! is_array($row['sampledValue'] ?? null) ||
                ! array_is_list($row['sampledValue'])) {
                throw new RuntimeException('Invalid source meter page requires reconciliation.');
            }
            foreach ($row['sampledValue'] as $sample) {
                if (! is_array($sample)) {
                    throw new RuntimeException('Invalid source sample requires reconciliation.');
                }
            }
            $sampleAt = SourceStatusTime::parse($row['timestamp'], 300);
            if ($sampleAt->lt($startedAt) || ($endedAt && $sampleAt->gt($endedAt))) {
                throw new RuntimeException('Source meter page contains an event outside its transaction.');
            }
            if ($sampleAt->lt($previousSourceAt)) {
                throw new RuntimeException('Source meter chronology regressed; reconciliation is required.');
            }
            $previousSourceAt = $sampleAt;
            $registers = array_values(array_filter($row['sampledValue'], fn ($s) => ($s['measurand'] ?? '') === 'Energy.Active.Import.Register' && empty($s['phase'])));
            if (count($registers) > 1) {
                throw new RuntimeException('Ambiguous register requires reconciliation.');
            }
            if ($registers) {
                // Even a repeated Transaction.Begin register is authenticated
                // metering evidence, not a free pass to skip malformed data.
                // A contradictory beginning meter must never be skipped before
                // accepting the next terminal reading and issuing an invoice.
                $reading = self::registerWh($registers[0]);
                if (($registers[0]['context'] ?? '') === 'Transaction.Begin' && $reading !== $baseline) {
                    throw new RuntimeException('Source beginning register conflicts with transaction baseline.');
                }
            }
            $previousId = $row['id'];
            if ($registers && ($registers[0]['context'] ?? '') === 'Transaction.End') {
                // A verified terminal source sample bounds the canonical
                // session. Post-terminal diagnostics may remain in this same
                // page but cannot prevent convergence or reopen charging.
                break;
            }
        }
        foreach ($rows as $row) {
            $samples = array_values(array_filter($row['sampledValue'], fn ($s) => ($s['measurand'] ?? '') === 'Energy.Active.Import.Register' && empty($s['phase'])));
            if (count($samples) > 1) {
                throw new RuntimeException('Ambiguous register requires reconciliation.');
            }
            if (! $samples || ($samples[0]['context'] ?? '') === 'Transaction.Begin') {
                $this->advanceCursor($link, $row['id'], $row['timestamp'], $authority, $target);

                continue;
            }
            $sample = $samples[0];
            $sampleAt = SourceStatusTime::parse($row['timestamp'], 300);
            if ($sampleAt->lt($startedAt) || ($endedAt && $sampleAt->gt($endedAt))) {
                throw new RuntimeException('Source energy sample lies outside its transaction.');
            }
            $meter = self::registerWh($sample);
            $ended = ($sample['context'] ?? '') === 'Transaction.End';
            if ($ended && ($tx['isActive'] || ! $tx['endTime'] || $tx['totalKwh'] === null || $meter - $baseline !== self::wh($tx['totalKwh'], 'kWh'))) {
                // Upstream final energy and end flags converge asynchronously.
                return $count;
            }
            if ($row['id'] > 1000000000) {
                throw new RuntimeException('Source sequence exceeds the inbox bound.');
            }
            $this->send($link, $tx, $ended ? 'ended' : 'meter', $row['id'], $meter, $ended ? $tx['endTime'] : $row['timestamp'], $authority, $target);
            $this->advanceCursor($link, $row['id'], $row['timestamp'], $authority, $target);
            $count++;
            if ($ended) {
                break;
            }
        }

        return $count;
    }
}
