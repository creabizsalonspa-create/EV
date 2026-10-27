<?php

namespace App\Services\Charging;

use App\Models\Charging\SessionLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Restartable local-simulator observer. Never dispatches or retries commands. */
class SourceConsumer
{
    private function graph(string $query, array $variables): array
    {
        $key = (string) config('csms.graphql_secret');
        if (strlen($key) < 32) {
            throw new RuntimeException('Source authentication is not configured.');
        }
        $response = Http::timeout(15)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])
            ->post('http://127.0.0.1:8095/v1/graphql', compact('query', 'variables'));
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
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $response = Http::timeout(15)->withoutRedirecting()->withHeaders(['Accept' => 'application/json', 'X-EV-Timestamp' => $timestamp, 'X-EV-Signature' => hash_hmac('sha256', $timestamp.'.'.$raw, $key)])
            ->withBody($raw, 'application/json')->post('http://127.0.0.1:8000/api/internal/csms/events');
        if (! $response->successful()) {
            throw new RuntimeException('Signed inbox rejected source event ('.$response->status().').');
        }
        // Inbox commit owns the durable cursor; never advance it on HTTP failure.
        $link->refresh();
    }

    public function consume(SessionLink $link): int
    {
        app(SessionBridge::class)->enabled();
        if (! preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $link->identifier) || ! in_array($link->protocol, ['1.6', '2.0.1'], true)) {
            throw new RuntimeException('Only provisioned local simulators are supported.');
        }
        $identity = $this->graph('query($station:String!,$tag:citext!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$station}},limit:2){id} Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id}}', ['station' => $link->identifier, 'tag' => 'EV-SIM-'.$link->charging_session_id]);
        if (count($identity['ChargingStations']) > 1 || count($identity['Authorizations']) > 1) {
            throw new RuntimeException('Ambiguous source identity requires reconciliation.');
        }
        if (! $identity['ChargingStations'] || ! $identity['Authorizations']) {
            return 0;
        }
        $records = $this->graph('query($station:Int!,$auth:Int!){Transactions(where:{tenantId:{_eq:1},stationId:{_eq:$station},authorizationId:{_eq:$auth}},limit:2){id transactionId remoteStartId meterStart startTime endTime isActive totalKwh}}', ['station' => $identity['ChargingStations'][0]['id'], 'auth' => $identity['Authorizations'][0]['id']])['Transactions'];
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
