<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Fixed non-secret device keys. No generic configuration writes or credentials. */
class ConfigurationProbe
{
    public function enabled(bool $dispatch = false): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('csms.session_bridge_enabled') && strlen((string) config('csms.graphql_secret')) >= 32, 503);
        abort_unless(in_array(rtrim((string) config('csms.operations_base_url'), '/'), ['http://127.0.0.1:8080', 'http://citrine:8080'], true) && in_array(config('csms.graphql_url'), ['http://127.0.0.1:8095/v1/graphql', 'http://charging-graphql:8080/v1/graphql'], true), 503);
        abort_if($dispatch && ! config('csms.configuration_read_enabled'), 503);
    }

    public function fields(string $protocol): array
    {
        return $protocol === '1.6' ? ['HeartbeatInterval', 'MeterValueSampleInterval', 'NumberOfConnectors'] : ['OCPPCommCtrlr.HeartbeatInterval', 'SampledDataCtrlr.TxUpdatedInterval', 'ClockCtrlr.TimeZone'];
    }

    public function payload(string $protocol): array
    {
        return $protocol === '1.6' ? ['key' => $this->fields($protocol)] : ['getVariableData' => array_map(function ($key) {
            [$component, $variable] = explode('.', $key);

            return ['component' => ['name' => $component], 'variable' => ['name' => $variable], 'attributeType' => 'Actual'];
        }, $this->fields($protocol))];
    }

    public function graph(string $query, array $variables): array
    {
        $r = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), compact('query', 'variables'));
        if (! $r->successful() || $r->json('errors') || ! is_array($r->json('data'))) {
            throw new RuntimeException('Authenticated source is unavailable.');
        }

        return $r->json('data');
    }

    public function station(object $binding, bool $online = true): int
    {
        $rows = $this->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol}}', ['name' => $binding->identifier])['ChargingStations'];
        abort_unless(count($rows) === 1 && in_array($rows[0]['protocol'], [$binding->protocol, 'ocpp'.$binding->protocol], true) && (! $online || $rows[0]['isOnline'] === true), 409);

        return $rows[0]['id'];
    }

    public function serviceToken(): string
    {
        $r = Http::connectTimeout(3)->timeout(5)->withoutRedirecting()->post(rtrim(config('csms.operations_base_url'), '/').'/dev/token', ['roles' => ['admin'], 'tenantId' => 1]);
        abort_unless($r->successful() && is_string($r->json('accessToken')) && $r->json('accessToken') !== '', 503);

        return $r->json('accessToken');
    }

    public function submit(object $binding, string $token, array $payload): bool
    {
        $path = $binding->protocol === '1.6' ? 'configuration/getConfiguration' : 'monitoring/getVariables';
        $r = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()->withToken($token)->post(rtrim(config('csms.operations_base_url'), '/').'/ocpp/'.$binding->protocol.'/'.$path.'?'.http_build_query(['identifier' => $binding->identifier, 'tenantId' => 1, 'staleAfterSeconds' => 10]), $payload);

        $reply = $r->json();

        // CitrineOS permits an optional diagnostic payload in its confirmation.
        // Only the single explicit success flag acknowledges transport; values
        // still require the separately correlated device reply below.
        return $r->successful() && is_array($reply) && array_is_list($reply) && count($reply) === 1 && ($reply[0]['success'] ?? null) === true;
    }

    public function snapshot(string $protocol, array $reply): array
    {
        $items = $reply[$protocol === '1.6' ? 'configurationKey' : 'getVariableResult'] ?? [];
        if (! is_array($items) || ! array_is_list($items) || count($items) > 50) {
            throw new RuntimeException('Invalid configuration reply.');
        }
        $unknown = $reply['unknownKey'] ?? [];
        if (! is_array($unknown) || ! array_is_list($unknown)) {
            throw new RuntimeException('Invalid configuration reply.');
        }
        $rows = [];
        foreach ($this->fields($protocol) as $key) {
            if ($protocol === '1.6') {
                $matches = array_values(array_filter($reply['configurationKey'] ?? [], fn ($row) => is_array($row) && ($row['key'] ?? null) === $key));
                $value = count($matches) === 1 ? ($matches[0]['value'] ?? null) : null;
                $status = $matches === [] ? (in_array($key, $unknown, true) ? 'unsupported' : 'missing') : (count($matches) === 1 && ! in_array($key, $unknown, true) ? 'observed' : 'ambiguous');
            } else {
                [$component, $variable] = explode('.', $key);
                $matches = array_values(array_filter($reply['getVariableResult'] ?? [], fn ($row) => is_array($row) && ($row['component']['name'] ?? null) === $component && ($row['variable']['name'] ?? null) === $variable && ($row['attributeType'] ?? 'Actual') === 'Actual' && ! isset($row['component']['instance']) && ! isset($row['component']['evse']) && ! isset($row['variable']['instance'])));
                $value = count($matches) === 1 && ($matches[0]['attributeStatus'] ?? null) === 'Accepted' ? ($matches[0]['attributeValue'] ?? null) : null;
                $status = count($matches) > 1 ? 'ambiguous' : ($matches === [] ? 'missing' : ($value !== null ? 'observed' : (in_array($matches[0]['attributeStatus'] ?? null, ['UnknownComponent', 'UnknownVariable', 'NotSupportedAttributeType', 'Rejected'], true) ? 'unsupported' : 'invalid_value')));
            }
            $safe = $status === 'observed' && $this->validValue($key, $value);
            $rows[] = ['key' => $key, 'status' => $status === 'observed' && ! $safe ? 'invalid_value' : $status, 'value' => $safe ? $value : null];
        }

        return $rows;
    }

    private function validValue(string $key, mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 120) {
            return false;
        }
        if ($key === 'ClockCtrlr.TimeZone') {
            return in_array($value, timezone_identifiers_list(), true) || $value === 'UTC';
        }
        if ($key === 'NumberOfConnectors') {
            return preg_match('/^[1-9][0-9]{0,2}$/D', $value) === 1;
        }

        return preg_match('/^(0|[1-9][0-9]{0,5})$/D', $value) && (int) $value <= 86400;
    }
}
