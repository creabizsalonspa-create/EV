<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signed CSMS events are not trusted as valid charging or billing evidence.
 * These requests are synthetic; no physical charger or PSP is contacted.
 */
class CsmsEventPayloadValidationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_correctly_signed_invalid_event_payloads_cannot_create_charging_or_billing_records(): void
    {
        Http::preventStrayRequests();

        $secret = 'agent9-payload-validation-signing-key-for-test';
        config([
            'csms.session_bridge_enabled' => true,
            'csms.event_secret' => $secret,
        ]);

        $validShape = [
            'event_id' => 'agent9-signed-invalid-payload',
            'session_id' => 2147483647,
            'identifier' => 'ev-test-agent9-validation',
            'protocol' => '1.6',
            'transaction_id' => '123456',
            'type' => 'started',
            'sequence' => 0,
            'meter_wh' => 0,
            'occurred_at' => now()->toIso8601String(),
        ];

        // The signature is valid for each *exact* body. Validation, not
        // missing authentication, must reject each unsafe data shape.
        $invalid = [
            'non_string_event_identity' => ['event_id' => ['attacker']],
            'oversize_event_identity' => ['event_id' => str_repeat('e', 101)],
            'non_integer_session' => ['session_id' => 'not-a-session'],
            'unbound_identifier_format' => ['identifier' => 'production-charger'],
            'unsupported_protocol' => ['protocol' => '3.0'],
            'nonnumeric_ocpp16_transaction' => ['transaction_id' => 'forged-transaction'],
            'oversize_transaction_identity' => ['transaction_id' => str_repeat('x', 37)],
            'unknown_event_type' => ['type' => 'payment_succeeded'],
            'negative_sequence' => ['sequence' => -1],
            'oversize_sequence' => ['sequence' => 1000000001],
            'negative_meter' => ['meter_wh' => -1],
            'excessive_meter' => ['meter_wh' => 9000000000001],
            'invalid_event_time' => ['occurred_at' => 'not-a-date'],
            'future_event_time' => ['occurred_at' => now()->addMinutes(7)->toIso8601String()],
            'negative_evse' => ['source_evse_number' => -1],
            'oversize_evse' => ['source_evse_number' => 1001],
            'zero_connector' => ['source_connector_number' => 0],
            'oversize_connector' => ['source_connector_number' => 101],
        ];

        foreach ($invalid as $name => $override) {
            $body = json_encode(array_replace($validShape, $override), JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $response = $this->call('POST', '/api/internal/csms/events', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_EV_TIMESTAMP' => $timestamp,
                'HTTP_X_EV_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            ], $body);

            $this->assertSame(422, $response->status(), $name.' must fail validation; received '.$response->getContent());
        }

        $this->assertDatabaseCount('csms_inbox_events', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }
}
