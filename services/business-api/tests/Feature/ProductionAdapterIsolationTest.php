<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live production must not be able to reuse the simulator-side signed event
 * and firmware adapters, even if an operator accidentally enables the flags.
 */
class ProductionAdapterIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_refuses_signed_local_csms_events_and_firmware_capabilities(): void
    {
        Http::preventStrayRequests();

        $key = 'agent9-disposable-signature-secret-at-least-32';
        config([
            'csms.session_bridge_enabled' => true,
            'csms.event_secret' => $key,
            'csms.firmware_enabled' => true,
        ]);

        $raw = json_encode([
            'event_id' => 'evt_agent9_production_denied',
            'session_id' => 1234,
            'identifier' => 'ev-test-prohibited',
            'protocol' => '2.0.1',
            'transaction_id' => 'qa-test-transaction',
            'type' => 'started',
            'sequence' => 0,
            'meter_wh' => 0,
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $key);

        $originalEnvironment = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->call('POST', '/api/internal/csms/events', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_EV_TIMESTAMP' => $timestamp,
                'HTTP_X_EV_SIGNATURE' => $signature,
            ], $raw)->assertStatus(503);

            $this->getJson('/api/internal/firmware/'.str_repeat('a', 64))
                ->assertStatus(503);

            $this->assertDatabaseCount('charging_sessions', 0);
            $this->assertDatabaseCount('charger_firmware_operations', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
            Http::assertNothingSent();
        } finally {
            app()->detectEnvironment(fn () => $originalEnvironment);
        }
    }
}
