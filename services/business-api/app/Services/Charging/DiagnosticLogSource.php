<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\Http;

class DiagnosticLogSource
{
    public const LIMIT = 524288;

    public function enabled(): void
    {
        app(ConfigurationProbe::class)->enabled();
        abort_unless(config('csms.diagnostic_logs_enabled') && in_array(config('csms.diagnostic_upload_origin'), ['http://127.0.0.1:8000', 'http://ev-business-api:8000'], true), 503);
    }

    public function authority(): array
    {
        $this->enabled();

        return [
            'operations' => rtrim((string) config('csms.operations_base_url'), '/'),
            'graphql' => config('csms.graphql_url'),
            'key' => (string) config('csms.graphql_secret'),
            'upload' => config('csms.diagnostic_upload_origin'),
        ];
    }

    public function requireAuthority(array $expected): void
    {
        abort_unless($this->authority() === $expected, 503, 'Diagnostic source authority changed; inspect again.');
    }

    public function payload(string $protocol, int $id, string $token): array
    {
        $origin = parse_url(config('csms.diagnostic_upload_origin'));
        $url = $origin['scheme'].'://diagnostic:'.$token.'@'.$origin['host'].':'.$origin['port'].'/api/internal/diagnostic-logs/'.$id;

        return $protocol === '1.6' ? ['location' => $url, 'retries' => 0] : ['logType' => 'DiagnosticsLog', 'requestId' => $id, 'retries' => 0, 'log' => ['remoteLocation' => $url]];
    }

    public function submit(object $binding, string $token, array $payload, array $authority): bool
    {
        $this->requireAuthority($authority);
        $path = $binding->protocol === '1.6' ? 'reporting/getDiagnostics' : 'reporting/getLog';
        $r = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()->withToken($token)->post($authority['operations'].'/ocpp/'.$binding->protocol.'/'.$path.'?'.http_build_query(['identifier' => $binding->identifier, 'tenantId' => 1, 'staleAfterSeconds' => 10]), $payload);
        $body = $r->json();

        return $r->successful() && is_array($body) && array_is_list($body) && count($body) === 1 && ($body[0]['success'] ?? null) === true;
    }
}
