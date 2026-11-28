<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\Schema;
use Throwable;

/** No external requests, database writes, credentials, or commands. */
final class CsmsPreflight
{
    private function endpointConfigured(): bool
    {
        $url = config('csms.graphql_url');

        return app(CsmsGraphqlTransport::class)->approved($url)
            && parse_url($url, PHP_URL_PATH) === '/v1/graphql';
    }

    private function schemaReady(): bool
    {
        try {
            return Schema::hasTable('csms_bindings')
                && Schema::hasTable('csms_inbox_events')
                && Schema::hasTable('charging_status_observations')
                && Schema::hasTable('charger_commands')
                && Schema::hasColumns('csms_session_links', [
                    'source_station_id', 'source_transaction_database_id',
                    'source_meter_cursor', 'source_meter_cursor_at', 'last_sequence',
                ])
                && Schema::hasIndex('csms_session_links', 'csms_link_binding_source_tx_unique')
                && Schema::hasIndex('charging_status_observations', 'charging_observation_event_freshness');
        } catch (Throwable) {
            // Missing schemas, permissions and unreachable databases are
            // indistinguishable from "not ready" at this safety boundary.
            return false;
        }
    }

    public function report(): array
    {
        $source = (string) config('csms.graphql_secret');
        $events = (string) config('csms.event_secret');
        $checks = [
            'local_simulator_only' => app()->environment(['local', 'testing'])
                && (bool) config('csms.simulator_enabled')
                && (bool) config('csms.session_bridge_enabled'),
            'independent_authenticated_source_and_inbox_keys' => strlen($source) >= 32
                && strlen($events) >= 32 && ! hash_equals($source, $events),
            'source_endpoint_format' => $this->endpointConfigured(),
            'canonical_charging_schema_and_indexes' => $this->schemaReady(),
        ];

        return [
            'status' => ! in_array(false, $checks, true) ? 'software_configuration_ready' : 'blocked',
            'software_configuration_ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'protocols_supported_by_simulator' => ['1.6', '2.0.1'],
            'source_connected' => false,
            'charger_hardware_verified' => false,
            'queue_worker_verified' => false,
            'certificate_lifecycle_accepted' => false,
            'production_ready' => false,
            'external_pilot_approved' => false,
            'device_commands_sent' => false,
            'database_mutations' => false,
        ];
    }
}
