<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class AvailabilitySource
{
    private const ACTION = 'ChangeAvailability';

    public function __construct(private readonly ConfigurationProbe $source) {}

    public function enabled(bool $dispatch = false): void
    {
        $this->source->enabled();
        abort_if($dispatch && ! config('csms.availability_command_enabled'), 503);
    }

    public function station(object $binding, bool $online = true): int
    {
        return $this->source->station($binding, $online);
    }

    public function authority(bool $dispatch = false): array
    {
        $this->enabled($dispatch);

        return [rtrim((string) config('csms.operations_base_url'), '/'),
            config('csms.graphql_url'), (string) config('csms.graphql_secret')];
    }

    public function requireAuthority(array $authority, bool $dispatch = false): void
    {
        abort_unless($this->authority($dispatch) === $authority, 503, 'Availability source authority changed.');
    }

    public function dispatchCredential(array $authority): \Closure
    {
        $this->requireAuthority($authority, true);

        return app(OperationsCredentials::class)->tokenForDispatch($authority[0]);
    }

    public function watermark(int $station): int
    {
        $rows = $this->source->messages($this->source->graph(
            'query($station:Int!,$action:String!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"csms"},type:{_eq:2},action:{_eq:$action}},order_by:{id:desc},limit:1){id createdAt}}',
            ['station' => $station, 'action' => self::ACTION],
        ), false);
        abort_unless(count($rows) <= 1, 503);

        return $rows[0]['id'] ?? 0;
    }

    public function submit(object $binding, \Closure $credential, array $payload, array $authority): bool
    {
        $this->requireAuthority($authority, true);
        $query = http_build_query(['identifier' => $binding->identifier, 'tenantId' => 1, 'staleAfterSeconds' => 8]);
        $url = $authority[0].'/ocpp/'.$binding->protocol.'/configuration/changeAvailability?'.$query;
        $token = $credential();
        $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withToken($token)->post($url, $payload);
        $reply = $response->json();

        return $response->successful() && is_array($reply) && array_is_list($reply) && count($reply) === 1
            && is_array($reply[0]) && ($reply[0]['success'] ?? null) === true;
    }

    public function confirmation(object $operation, array $authority): ?array
    {
        $this->requireAuthority($authority);
        $from = CarbonImmutable::parse($operation->attempted_at, 'UTC');
        $until = $from->addSeconds(16);
        $requests = $this->source->messages($this->source->graph(
            'query($station:Int!,$action:String!,$watermark:Int!,$after:timestamptz!,$until:timestamptz!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"csms"},type:{_eq:2},action:{_eq:$action},id:{_gt:$watermark},createdAt:{_gte:$after,_lte:$until}},limit:2){id createdAt payload correlationId}}',
            ['station' => $operation->source_station_id, 'action' => self::ACTION, 'watermark' => $operation->source_watermark, 'after' => $from->toISOString(), 'until' => $until->toISOString()],
        ), false);
        $this->requireAuthority($authority);
        if (count($requests) !== 1) {
            return null;
        }
        $request = $requests[0];
        $requestAt = SourceStatusTime::parse($request['createdAt']);
        $expected = json_decode($operation->source_payload, true, flags: JSON_THROW_ON_ERROR);
        if ($request['id'] <= $operation->source_watermark || $requestAt->lt($from) || $requestAt->gt($until)
            || ! $this->matches($request['payload'] ?? null, $expected)
            || ! is_string($request['correlationId'] ?? null) || strlen($request['correlationId']) < 1 || strlen($request['correlationId']) > 64) {
            return null;
        }
        $replies = $this->source->messages($this->source->graph(
            'query($station:Int!,$request:Int!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},requestMessageId:{_eq:$request},type:{_in:[3,4]}},limit:2){id type payload createdAt correlationId requestMessageId}}',
            ['station' => $operation->source_station_id, 'request' => $request['id']],
        ), true);
        $this->requireAuthority($authority);
        if (count($replies) !== 1) {
            return null;
        }
        $reply = $replies[0];
        $observed = SourceStatusTime::parse($reply['createdAt']);
        if ($reply['id'] <= $request['id'] || ($reply['requestMessageId'] ?? null) !== $request['id']
            || ($reply['correlationId'] ?? null) !== $request['correlationId'] || $observed->lt($requestAt)) {
            return null;
        }
        $status = $reply['type'] === 3 ? AvailabilityCommand::confirmation($reply['payload']['status'] ?? null) : 'outcome_unknown';
        if ($status === 'outcome_unknown') {
            return null;
        }

        return ['status' => $status, 'source_request_id' => $request['id'], 'source_reply_id' => $reply['id'], 'observed_at' => $observed->utc()->format('Y-m-d H:i:s.u')];
    }

    private function matches(mixed $actual, array $expected): bool
    {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (is_array($value) ? ! $this->matches($actual[$key] ?? null, $value) : ($actual[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
