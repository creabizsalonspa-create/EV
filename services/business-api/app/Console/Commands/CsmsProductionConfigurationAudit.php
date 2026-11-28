<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CsmsProductionConfigurationAudit extends Command
{
    protected $signature = 'csms:production-config-audit';

    protected $description = 'Read-only production charging configuration audit; never authorizes deployment or hardware';

    private function https(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])$/D', $parts['host']) === 1
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && (! isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535));
    }

    private function graphqlEndpoint(mixed $value): bool
    {
        // Match the authenticated GraphQL transport's nonempty path contract.
        // A bare HTTPS origin is not a configured GraphQL endpoint.
        if (! $this->https($value) || strlen($value) > 512) {
            return false;
        }
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && strlen($path) >= 2 && str_starts_with($path, '/');
    }

    private function independentSecrets(): bool
    {
        $secrets = [config('csms.graphql_secret'), config('csms.event_secret'), config('csms.ingress_secret'), config('csms.operations_oauth_client_secret')];
        foreach ($secrets as $secret) {
            if (! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 4096 || preg_match('/[\x00-\x20\x7f]/', $secret)) {
                return false;
            }
        }

        return count(array_unique($secrets, SORT_STRING)) === count($secrets);
    }

    private function pinnedKeys(): bool
    {
        $keys = config('csms.operations_oauth_public_keys');
        if (! is_array($keys) || count($keys) < 1 || count($keys) > 32) {
            return false;
        }
        foreach ($keys as $kid => $pem) {
            if (! is_string($kid) || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kid)
                || ! is_string($pem) || strlen($pem) > 16384 || ! str_starts_with($pem, '-----BEGIN PUBLIC KEY-----')) {
                return false;
            }
            $key = openssl_pkey_get_public($pem);
            $details = $key ? openssl_pkey_get_details($key) : false;
            if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                return false;
            }
        }

        return true;
    }

    private function pinsConfigured(): bool
    {
        $pins = config('csms.certificate_issuer_pins');
        if (! is_array($pins) || ! array_is_list($pins) || count($pins) < 1 || count($pins) > 64) {
            return false;
        }
        foreach ($pins as $pin) {
            if (! is_string($pin) || ! preg_match('/^[a-f0-9]{64}$/D', $pin)) {
                return false;
            }
        }

        // ChargerCertificateVerifier compares lowercase fingerprints exactly
        // and excludes locally denied issuers. A configured but completely
        // denied trust set cannot pass even this configuration-only audit.
        $denied = config('csms.certificate_denied_issuers', []);
        if (! is_array($denied) || ! array_is_list($denied)) {
            return false;
        }
        foreach ($denied as $pin) {
            if (! is_string($pin) || ! preg_match('/^[a-f0-9]{64}$/D', $pin)) {
                return false;
            }
        }

        return count(array_unique($pins)) === count($pins)
            && array_diff($pins, $denied) !== [];
    }

    private function schemaConfigured(): bool
    {
        try {
            foreach (['csms_bindings', 'charger_source_registrations', 'charger_certificates', 'csms_session_links', 'csms_inbox_events', 'charging_status_observations', 'charger_commands'] as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function handle(): int
    {
        $base = config('csms.operations_base_url');
        $client = config('csms.operations_oauth_client_id');
        $scope = config('csms.operations_oauth_scope');
        $checks = [
            'production_environment' => app()->environment('production'),
            'simulator_and_local_http_disabled' => ! config('csms.simulator_enabled')
                && ! config('csms.session_bridge_enabled') && ! config('csms.ingress_allow_local_http')
                && ! config('csms.upstream_registration_allow_local_http'),
            'source_https' => $this->graphqlEndpoint(config('csms.graphql_url')),
            'operations_https_and_audience' => $this->https($base)
                && $base === rtrim($base, '/') && $base === config('csms.operations_oauth_audience'),
            'oauth_https_and_identity' => config('csms.operations_auth_mode') === 'oauth2'
                && $this->https(config('csms.operations_oauth_token_url')) && $this->https(config('csms.operations_oauth_issuer'))
                && is_string($client) && preg_match('/^[A-Za-z0-9._-]{1,200}$/D', $client) === 1
                && is_string($scope) && preg_match('/^[A-Za-z0-9:._-]{1,100}$/D', $scope) === 1,
            'independent_service_secrets' => $this->independentSecrets(),
            'pinned_rsa_public_keys' => $this->pinnedKeys(),
            'certificate_issuer_pins_configured' => $this->pinsConfigured(),
            'canonical_tables_present' => $this->schemaConfigured(),
        ];
        $complete = ! in_array(false, $checks, true);
        $this->line(json_encode([
            'status' => $complete ? 'configuration_checks_passed_live_validation_required' : 'blocked',
            'configuration_checks_passed' => $complete,
            'checks' => $checks,
            'source_connected' => false,
            'oauth_token_verified' => false,
            'certificate_chain_verified' => false,
            'production_transport_verified' => false,
            'device_commands_sent' => false,
            'database_mutations' => false,
            'production_ready' => false,
            'physical_hardware_accepted' => false,
        ], JSON_THROW_ON_ERROR));

        return $complete ? self::SUCCESS : self::FAILURE;
    }
}
