<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fail closed before CSMS/network access: guessing IDs or sending a plausible
 * OCPP payload must never dispatch a command without an authenticated actor.
 * This is intentionally read-only (no fixtures, hardware or production data).
 */
class AnonymousChargingMutationSecurityTest extends TestCase
{
    public function test_anonymous_mutations_cannot_start_stop_reconcile_or_bind_a_charger(): void
    {
        Http::preventStrayRequests();

        $id = '2147483647'; // deliberately nonexistent synthetic ID
        $cases = [
            ['/api/stations/'.$id.'/csms-binding', [
                'identifier' => 'ev-test-anonymous-control', 'protocol' => '1.6',
            ]],
            ['/api/stations/'.$id.'/commands', [
                'action' => 'start', 'idempotency_key' => 'anonymous-start-0001',
            ]],
            ['/api/reservations/'.$id.'/csms-start', [
                'idempotency_key' => 'anonymous-reservation-start-0001',
            ]],
            ['/api/charging-sessions/'.$id.'/csms-stop', [
                'idempotency_key' => 'anonymous-stop-0001',
            ]],
            ['/api/charging-sessions/'.$id.'/reconcile', [
                'idempotency_key' => 'anonymous-reconcile-0001',
            ]],
            ['/api/reservations/'.$id.'/check-in', [
                'idempotency_key' => 'anonymous-check-in-0001',
            ]],
        ];

        foreach ($cases as [$path, $payload]) {
            $response = $this->postJson($path, $payload);

            $response->assertUnauthorized();
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), $path);
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), $path);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
        }

        Http::assertNothingSent();
    }

    public function test_unsigned_internal_service_calls_cannot_mutate_or_expose_device_state(): void
    {
        Http::preventStrayRequests();

        $requests = [
            ['POST', '/api/internal/charger-ingress/authenticate', ['identifier' => 'ev-test-untrusted']],
            ['POST', '/api/internal/commercial/campaign-deliveries', ['event' => 'forged']],
            ['POST', '/api/internal/csms/events', ['event_id' => 'forged']],
            ['PUT', '/api/internal/diagnostic-logs/2147483647', ['status' => 'completed']],
            ['GET', '/api/internal/firmware/synthetic-untrusted-token', null],
            ['POST', '/api/internal/remote-session-authorize', ['idToken' => 'untrusted']],
            ['POST', '/api/internal/rider-authorize', ['idToken' => 'untrusted']],
            ['POST', '/api/internal/rider-oauth/token', ['grant_type' => 'client_credentials']],
        ];

        foreach ($requests as [$method, $url, $payload]) {
            $response = match ($method) {
                'GET' => $this->getJson($url),
                'PUT' => $this->putJson($url, $payload),
                default => $this->postJson($url, $payload),
            };
            $this->assertContains($response->status(),
                [400, 401, 403, 404, 422, 429, 503],
                'Unsigned service request must fail closed without HTTP 500 or 2xx: '.$url);
            $this->assertNotSame(200, $response->status(), $url);
        }

        Http::assertNothingSent();
    }
}
