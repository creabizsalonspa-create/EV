<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsmsProductionConfigurationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_configuration_audit_is_offline_and_never_authorizes_release(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $public = openssl_pkey_get_details($key)['key'];
        config([
            'csms.simulator_enabled' => false,
            'csms.session_bridge_enabled' => false,
            'csms.ingress_allow_local_http' => false,
            'csms.upstream_registration_allow_local_http' => false,
            'csms.graphql_url' => 'https://source.example.test/v1/graphql',
            'csms.operations_base_url' => 'https://operations.example.test',
            'csms.operations_oauth_audience' => 'https://operations.example.test',
            'csms.operations_auth_mode' => 'oauth2',
            'csms.operations_oauth_token_url' => 'https://identity.example.test/token',
            'csms.operations_oauth_issuer' => 'https://identity.example.test',
            'csms.operations_oauth_client_id' => 'test-service',
            'csms.operations_oauth_scope' => 'csms:operations',
            'csms.operations_oauth_public_keys' => ['current' => $public],
            'csms.graphql_secret' => str_repeat('a', 64),
            'csms.event_secret' => str_repeat('b', 64),
            'csms.ingress_secret' => str_repeat('c', 64),
            'csms.operations_oauth_client_secret' => str_repeat('d', 64),
            'csms.certificate_issuer_pins' => [str_repeat('e', 64)],
            'csms.certificate_denied_issuers' => [],
        ]);
        $this->assertSame(1, Artisan::call('csms:production-config-audit'));
        $this->assertFalse(json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR)['checks']['production_environment']);
        $prior = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->assertSame(0, Artisan::call('csms:production-config-audit'));
            $report = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
            $this->assertTrue($report['configuration_checks_passed']);
            $this->assertSame('configuration_checks_passed_live_validation_required', $report['status']);
            foreach (['source_connected', 'oauth_token_verified', 'certificate_chain_verified', 'production_transport_verified',
                'device_commands_sent', 'database_mutations', 'production_ready', 'physical_hardware_accepted'] as $field) {
                $this->assertFalse($report[$field]);
            }

            foreach ([
                ['csms.simulator_enabled', true, 'simulator_and_local_http_disabled'],
                ['csms.session_bridge_enabled', true, 'simulator_and_local_http_disabled'],
                ['csms.ingress_allow_local_http', true, 'simulator_and_local_http_disabled'],
                ['csms.graphql_url', 'http://source.example.test/v1/graphql', 'source_https'],
                ['csms.graphql_url', 'https://source.example.test', 'source_https'],
                ['csms.graphql_url', 'https://source.example.test/', 'source_https'],
                ['csms.graphql_url', 'https://source.example.test/'.str_repeat('x', 512), 'source_https'],
                ['csms.graphql_url', 'https://user:PRIVATE-PASSWORD@source.example.test/v1/graphql', 'source_https'],
                ['csms.operations_oauth_audience', 'https://other.example.test', 'operations_https_and_audience'],
                ['csms.operations_auth_mode', 'development', 'oauth_https_and_identity'],
                ['csms.operations_oauth_token_url', 'https://identity.example.test/token?secret=PRIVATE-VALUE', 'oauth_https_and_identity'],
                ['csms.event_secret', str_repeat('a', 64), 'independent_service_secrets'],
                ['csms.operations_oauth_public_keys', ['current' => 'PRIVATE-INVALID-KEY'], 'pinned_rsa_public_keys'],
                ['csms.certificate_issuer_pins', [str_repeat('e', 64), str_repeat('E', 64)], 'certificate_issuer_pins_configured'],
                ['csms.certificate_issuer_pins', [str_repeat('E', 64)], 'certificate_issuer_pins_configured'],
                ['csms.certificate_issuer_pins', [str_repeat('e', 64), str_repeat('e', 64)], 'certificate_issuer_pins_configured'],
                ['csms.certificate_issuer_pins', ['named' => str_repeat('e', 64)], 'certificate_issuer_pins_configured'],
                ['csms.certificate_denied_issuers', [str_repeat('e', 64)], 'certificate_issuer_pins_configured'],
                ['csms.certificate_denied_issuers', [['PRIVATE-INVALID-PIN']], 'certificate_issuer_pins_configured'],
            ] as [$field, $value, $check]) {
                $original = config($field);
                config([$field => $value]);
                $this->assertSame(1, Artisan::call('csms:production-config-audit'));
                $output = Artisan::output();
                $report = json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR);
                $this->assertFalse($report['checks'][$check]);
                $this->assertFalse($report['production_ready']);
                $this->assertFalse($report['device_commands_sent']);
                $this->assertStringNotContainsString('PRIVATE-', $output);
                $this->assertStringNotContainsString(str_repeat('a', 64), $output);
                config([$field => $original]);
            }
            // A matching but trailing-slash audience is also incompatible:
            // operations callers remove the slash before obtaining a token.
            config([
                'csms.operations_base_url' => 'https://operations.example.test/',
                'csms.operations_oauth_audience' => 'https://operations.example.test/',
            ]);
            $this->assertSame(1, Artisan::call('csms:production-config-audit'));
            $this->assertFalse(json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR)['checks']['operations_https_and_audience']);
            config([
                'csms.operations_base_url' => 'https://operations.example.test',
                'csms.operations_oauth_audience' => 'https://operations.example.test',
                'csms.certificate_issuer_pins' => [str_repeat('e', 64), str_repeat('f', 64)],
                'csms.certificate_denied_issuers' => [str_repeat('e', 64)],
            ]);
            $this->assertSame(0, Artisan::call('csms:production-config-audit'));
            $this->assertTrue(json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR)['checks']['certificate_issuer_pins_configured']);
        } finally {
            app()->detectEnvironment(fn () => $prior);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
