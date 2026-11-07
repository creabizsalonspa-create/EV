<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Independently checks fail-closed signed simulator event ingress. Synthetic
 * event bodies are never forwarded to a physical CSMS or payment provider.
 */
class CsmsEventIngressSignatureSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'agent9-isolated-event-ingress-signing-key-123456789';

    private function submit(string $body, string $timestamp, string $signature): TestResponse
    {
        return $this->call('POST', '/api/internal/csms/events', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EV_TIMESTAMP' => $timestamp,
            'HTTP_X_EV_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_missing_untrusted_expired_and_future_signed_ingress_evidence_is_denied(): void
    {
        Http::preventStrayRequests();
        config([
            'csms.session_bridge_enabled' => true,
            'csms.event_secret' => self::SECRET,
        ]);

        $event = json_encode([
            'event_id' => 'evt_agent9_untrusted',
            'session_id' => 1234567,
            'identifier' => 'ev-test-agent9-no-charger',
            'protocol' => '1.6',
            'transaction_id' => 'qa-no-transaction',
            'type' => 'started',
            'sequence' => 0,
            'meter_wh' => 0,
            'occurred_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $now = time();
        $signature = fn (int $timestamp, string $key = self::SECRET) => hash_hmac('sha256', $timestamp.'.'.$event, $key);

        $this->submit($event, (string) $now, '')->assertUnauthorized();
        $this->submit($event, (string) $now, str_repeat('0', 64))->assertUnauthorized();
        $this->submit($event, (string) $now, $signature($now, str_repeat('w', 48)))->assertUnauthorized();
        $this->submit($event, (string) ($now - 301), $signature($now - 301))->assertUnauthorized();
        // Give the future-window negative test margin for a one-second clock tick.
        $this->submit($event, (string) ($now + 305), $signature($now + 305))->assertUnauthorized();
        $this->submit($event, '0x'.dechex($now), $signature($now))->assertUnauthorized();

        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_correct_signature_still_enforces_body_budget_and_json_structure(): void
    {
        Http::preventStrayRequests();
        config([
            'csms.session_bridge_enabled' => true,
            'csms.event_secret' => self::SECRET,
        ]);
        $timestamp = (string) time();
        $signature = fn (string $body) => hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        $large = str_repeat('a', 16385);
        $this->submit($large, $timestamp, $signature($large))->assertStatus(413);

        $malformed = '{"event_id":';
        $this->submit($malformed, $timestamp, $signature($malformed))
            ->assertUnprocessable();

        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }
}
