<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\Http;

class DiagnosticLogSource
{
    public const LIMIT = 524288;

    public function enabled(?object $binding = null): void
    {
        if (! empty($binding->diagnostic_direct)) {
            abort_unless(config('csms.production_diagnostics_enabled') && config('csms.diagnostic_logs_enabled') && config('csms.operations_auth_mode') === 'oauth2', 503, 'Registered-source diagnostics is disabled.');
            foreach (['operations_base_url', 'graphql_url', 'production_diagnostic_upload_origin'] as $key) {
                $url = config('csms.'.$key);
                $parts = is_string($url) ? parse_url($url) : false;
                abort_unless(is_array($parts) && ($parts['scheme'] ?? null) === 'https' && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && strlen($url) <= 2048 && ! preg_match('/[\x00-\x20\x7f\\\\]/', $url), 503, 'Fixed HTTPS diagnostic endpoints are required.');
                if ($key === 'production_diagnostic_upload_origin') {
                    abort_unless(! isset($parts['path']) || $parts['path'] === '/', 503, 'Use a fixed HTTPS upload origin.');
                }
            }
            abort_unless(strlen((string) config('csms.graphql_secret')) >= 32, 503);

            return;
        }
        app(ConfigurationProbe::class)->enabled();
        abort_unless(config('csms.diagnostic_logs_enabled') && in_array(config('csms.diagnostic_upload_origin'), ['http://127.0.0.1:8000', 'http://ev-business-api:8000'], true), 503);
    }

    public function payload(string $protocol, int $id, string $token, ?object $binding = null): array
    {
        $origin = parse_url(config(! empty($binding->diagnostic_direct) ? 'csms.production_diagnostic_upload_origin' : 'csms.diagnostic_upload_origin'));
        $url = $origin['scheme'].'://diagnostic:'.$token.'@'.$origin['host'].(isset($origin['port']) ? ':'.$origin['port'] : '').'/api/internal/diagnostic-logs/'.$id;

        return $protocol === '1.6' ? ['location' => $url, 'retries' => 0] : ['logType' => 'DiagnosticsLog', 'requestId' => $id, 'retries' => 0, 'log' => ['remoteLocation' => $url]];
    }

    public function authority(object $binding): array
    {
        $this->enabled($binding);

        return [rtrim((string) config('csms.operations_base_url'), '/'),
            config('csms.graphql_url'), (string) config('csms.graphql_secret'),
            config(! empty($binding->diagnostic_direct) ? 'csms.production_diagnostic_upload_origin' : 'csms.diagnostic_upload_origin')];
    }

    public function requireAuthority(object $binding, array $authority): void
    {
        abort_unless($this->authority($binding) === $authority, 503, 'Diagnostic source authority changed.');
    }

    public function submit(object $binding, \Closure $credential, array $payload, array $authority): bool
    {
        $this->requireAuthority($binding, $authority);
        app(DiagnosticAuthority::class)->dispatchable($binding);
        $path = $binding->protocol === '1.6' ? 'reporting/getDiagnostics' : 'reporting/getLog';
        $token = $credential();
        $r = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()->withToken($token)->post($authority[0].'/ocpp/'.$binding->protocol.'/'.$path.'?'.http_build_query(['identifier' => $binding->identifier, 'tenantId' => 1, 'staleAfterSeconds' => 10]), $payload);
        $body = $r->json();

        return $r->successful() && is_array($body) && array_is_list($body) && count($body) === 1 && ($body[0]['success'] ?? null) === true;
    }
}
