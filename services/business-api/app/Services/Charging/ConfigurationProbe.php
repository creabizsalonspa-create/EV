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
        // This public helper must remain safe even when a future caller
        // forgets to invoke the stricter dispatch-only enabled() gate.
        $url = app(CsmsGraphqlTransport::class)->requireApproved();
        $key = (string) config('csms.graphql_secret');
        abort_unless(strlen($key) >= 32, 503);
        $r = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])->post($url, compact('query', 'variables'));
        abort_unless(app(CsmsGraphqlTransport::class)->requireApproved() === $url
            && hash_equals($key, (string) config('csms.graphql_secret')), 503, 'Configuration source authority changed.');
        if (! $r->successful() || $r->json('errors') || ! is_array($r->json('data'))) {
            throw new RuntimeException('Authenticated source is unavailable.');
        }

        return $r->json('data');
    }

    /**
     * Explicit source projection contract for a correlated configuration
     * request/reply. The filters in a GraphQL query do not justify trusting
     * an absent, malformed or unexpectedly typed response object.
     */
    public function messages(array $data, bool $reply): array
    {
        $rows = $data['OCPPMessages'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 2, 503, 'Malformed source command messages.');
        foreach ($rows as $row) {
            abort_unless(is_array($row) && is_int($row['id'] ?? null) && $row['id'] > 0
                && $row['id'] <= 2147483647 && is_string($row['createdAt'] ?? null), 503, 'Malformed source command identity.');
            SourceStatusTime::parse($row['createdAt'], 300);
            if ($reply) {
                abort_unless(is_int($row['type'] ?? null) && in_array($row['type'], [3, 4], true)
                    && array_key_exists('payload', $row)
                    && ($row['type'] !== 3 || is_array($row['payload'])), 503, 'Malformed source command reply.');
            }
        }

        return $rows;
    }

    public function station(object $binding, bool $online = true): int
    {
        $data = $this->graph('query($name:String!){ChargingStations(where:{tenantId:{_eq:1},ocppConnectionName:{_eq:$name}},limit:2){id isOnline protocol}}', ['name' => $binding->identifier]);
        $rows = $data['ChargingStations'] ?? null;
        // A malformed Hasura projection must never become an online charger
        // identity. Fail closed before retrieving an operations token or
        // dispatching even a simulator-only read command.
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 2, 503, 'Malformed source station projection.');
        abort_unless(count($rows) === 1, 409, 'Source station identity is absent or ambiguous.');
        $station = $rows[0];
        abort_unless(is_array($station) && is_int($station['id'] ?? null) && $station['id'] > 0
            && is_string($station['protocol'] ?? null) && is_bool($station['isOnline'] ?? null),
            503, 'Malformed source station identity.');
        abort_unless(in_array($station['protocol'], [$binding->protocol, 'ocpp'.$binding->protocol], true)
            && (! $online || $station['isOnline'] === true), 409, 'Source station protocol or connection is not ready.');

        return $station['id'];
    }

    public function dispatchCredential(string $operationsBase): \Closure
    {
        $this->requireOperationsBase($operationsBase);

        return app(OperationsCredentials::class)->tokenForDispatch($operationsBase);

    }

    public function requireOperationsBase(string $operationsBase): void
    {
        $this->enabled(true);
        abort_unless(rtrim((string) config('csms.operations_base_url'), '/') === $operationsBase, 409, 'Operations authority changed before configuration dispatch.');
    }

    public function submit(object $binding, \Closure $credential, array $payload, string $operationsBase): bool
    {
        $this->requireOperationsBase($operationsBase);
        $path = $binding->protocol === '1.6' ? 'configuration/getConfiguration' : 'monitoring/getVariables';
        $token = $credential();
        $r = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()->withToken($token)->post($operationsBase.'/ocpp/'.$binding->protocol.'/'.$path.'?'.http_build_query(['identifier' => $binding->identifier, 'tenantId' => 1, 'staleAfterSeconds' => 10]), $payload);

        $reply = $r->json();

        // CitrineOS permits an optional diagnostic payload in its confirmation.
        // Only the single explicit success flag acknowledges transport; values
        // still require the separately correlated device reply below.
        return $r->successful() && is_array($reply) && array_is_list($reply) && count($reply) === 1 && ($reply[0]['success'] ?? null) === true;
    }

    public function snapshot(string $protocol, array $reply): array
    {
        $field = $protocol === '1.6' ? 'configurationKey' : 'getVariableResult';
        // An absent optional list may indicate unsupported values; an
        // explicitly null or malformed list is not device evidence.
        $items = array_key_exists($field, $reply) ? $reply[$field] : [];
        if (! is_array($items) || ! array_is_list($items) || count($items) > 50) {
            throw new RuntimeException('Invalid configuration reply.');
        }
        $unknown = array_key_exists('unknownKey', $reply) ? $reply['unknownKey'] : [];
        if (! is_array($unknown) || ! array_is_list($unknown) || count($unknown) > 50) {
            throw new RuntimeException('Invalid configuration reply.');
        }
        foreach ($unknown as $key) {
            if (! is_string($key) || strlen($key) > 120) {
                throw new RuntimeException('Invalid configuration reply.');
            }
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item[$protocol === '1.6' ? 'key' : 'component'] ?? null)
                && $protocol !== '1.6') {
                throw new RuntimeException('Invalid configuration reply.');
            }
            if ($protocol === '1.6' && ! is_string($item['key'] ?? null)) {
                throw new RuntimeException('Invalid configuration reply.');
            }
            if ($protocol === '2.0.1' && (! is_string($item['component']['name'] ?? null)
                || ! is_string($item['variable']['name'] ?? null))) {
                throw new RuntimeException('Invalid configuration reply.');
            }
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
