<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Public OCPI protocol routes authenticate the remote network independently
 * of customer/vendor/Admin sessions; no unsigned CDR may become billing fact.
 */
class OcpiCdrIngressAuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_ocpi_cdr_and_session_requests_never_create_source_or_finance_records(): void
    {
        Http::preventStrayRequests();
        config([
            'ocpi.connections.receiver' => [
                'managed_credentials' => true,
                'incoming_modules' => ['sessions', 'cdrs'],
                'origin' => 'https://partner.example.invalid',
                'authorization_token' => base64_encode('UNTRUSTED-BOOTSTRAP'),
                'party_country' => 'NL',
                'party_id' => 'ABC',
                'party_role' => 'CPO',
            ],
        ]);

        $cdr = [
            'country_code' => 'NL',
            'party_id' => 'ABC',
            'id' => 'qa-untrusted-cdr',
            'session_id' => 'qa-untrusted-session',
            'last_updated' => now()->toISOString(),
            'start_date_time' => now()->subMinute()->toISOString(),
            'end_date_time' => now()->toISOString(),
            'currency' => 'EUR',
            'total_energy' => '1.00',
            'total_time' => '1',
            'total_cost' => ['excl_vat' => '0.50'],
            'cdr_location' => ['id' => 'remote', 'evse_uid' => 'evse', 'connector_id' => '1'],
            'cdr_token' => ['uid' => 'UNTRUSTED-TOKEN'],
        ];

        foreach ([
            [],
            ['Authorization' => 'Token '.base64_encode('INVALID-PEER')],
            ['Authorization' => 'Bearer forged-user-token'],
        ] as $headers) {
            $this->postJson('/api/roaming-protocol/2.2.1/cdrs', $cdr, $headers)
                ->assertUnauthorized();
            $this->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/qa-untrusted-session', [
                'country_code' => 'NL', 'party_id' => 'ABC',
                'id' => 'qa-untrusted-session',
                'last_updated' => now()->toISOString(),
            ], $headers)->assertUnauthorized();
        }

        foreach (['admin', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->postJson('/api/roaming-protocol/2.2.1/cdrs', $cdr)->assertUnauthorized();
            $this->getJson('/api/roaming-protocol/2.2.1/cdrs/qa-untrusted-cdr')->assertUnauthorized();
        }

        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('ocpi_record_receipts', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        Http::assertNothingSent();
    }
}
