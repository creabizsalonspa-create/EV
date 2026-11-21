<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\OcpiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesOcpiTechnicalAgreement;
use Tests\TestCase;

class OcpiCredentialTraceTest extends TestCase
{
    use CreatesOcpiTechnicalAgreement;
    use RefreshDatabase;

    private function fixture(): OcpiProvider
    {
        Http::preventStrayRequests();
        config([
            'ocpi.connections.trace-contract' => [
                'managed_credentials' => true,
                'origin' => 'https://partner.example',
                'versions_path' => '/ocpi/versions',
                'authorization_token' => base64_encode('SYNTHETIC-NON-LIVE-BOOTSTRAP'),
                'party_country' => 'NL',
                'party_id' => 'ABC',
                'party_role' => 'CPO',
            ],
            'ocpi.local_credentials' => [
                'url' => 'https://platform.example/api/roaming-protocol/versions',
                'roles' => [[
                    'role' => 'EMSP',
                    'country_code' => 'AE',
                    'party_id' => 'EVX',
                    'business_details' => ['name' => 'Synthetic partner trace test'],
                ]],
            ],
        ]);

        return OcpiProvider::create([
            'owner_id' => User::factory()->create(['role' => 'vendor'])->id,
            'name' => 'Synthetic peer trace test',
            'connection_key' => 'trace-contract',
            'authorized' => true,
        ]);
    }

    private function reply(string $url): array
    {
        $data = str_ends_with($url, '/ocpi/versions')
            ? [['version' => '2.2.1', 'url' => 'https://partner.example/ocpi/2.2.1']]
            : ['version' => '2.2.1', 'endpoints' => [[
                'identifier' => 'credentials', 'role' => 'RECEIVER',
                'url' => 'https://partner.example/ocpi/2.2.1/credentials',
            ]]];

        return ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $data];
    }

    public function test_managed_credential_discovery_checks_both_tracing_ids_on_each_page(): void
    {
        $provider = $this->fixture();
        Http::fake(function ($request) {
            return Http::response($this->reply($request->url()), 200, [
                'X-Request-ID' => $request->header('X-Request-ID')[0],
                'X-Correlation-ID' => $request->header('X-Correlation-ID')[0],
            ]);
        });

        $endpoint = app(CredentialExchange::class)->endpoint($provider, base64_encode('SYNTHETIC-NON-LIVE-BOOTSTRAP'));
        $this->assertSame('https://partner.example/ocpi/2.2.1/credentials', $endpoint);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_credential_links', 0);
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
    }

    public function test_partial_and_mismatched_credential_echoes_cannot_be_accepted_or_replayed(): void
    {
        $provider = $this->fixture();
        $mode = 'partial';
        Http::fake(function ($request) use (&$mode) {
            $headers = ['X-Request-ID' => $request->header('X-Request-ID')[0]];
            if ($mode === 'mismatch') {
                $headers['X-Correlation-ID'] = 'wrong-peer-correlation';
            }

            return Http::response($this->reply($request->url()), 200, $headers);
        });

        foreach (['partial', 'mismatch'] as $mode) {
            try {
                app(CredentialExchange::class)->endpoint($provider, base64_encode('SYNTHETIC-NON-LIVE-BOOTSTRAP'));
                $this->fail('Unverified credentials discovery was accepted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('credentials request failed', $error->getMessage());
            }
        }
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_credential_links', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['replayed'])]
    #[TestWith(['partial'])]
    #[TestWith(['swapped'])]
    public function test_second_discovery_response_cannot_reuse_or_change_the_first_request_trace(string $mode): void
    {
        $provider = $this->fixture();
        $first = null;
        Http::fake(function ($request) use (&$first, $mode) {
            $headers = [
                'X-Request-ID' => $request->header('X-Request-ID')[0],
                'X-Correlation-ID' => $request->header('X-Correlation-ID')[0],
            ];
            if ($first === null) {
                $first = $headers;
            } elseif ($mode === 'replayed') {
                $headers = $first;
            } elseif ($mode === 'partial') {
                unset($headers['X-Correlation-ID']);
            } else {
                $headers = [
                    'X-Request-ID' => $headers['X-Correlation-ID'],
                    'X-Correlation-ID' => $headers['X-Request-ID'],
                ];
            }

            return Http::response($this->reply($request->url()), 200, $headers);
        });
        try {
            app(CredentialExchange::class)->endpoint($provider, base64_encode('SYNTHETIC-NON-LIVE-BOOTSTRAP'));
            $this->fail('Second-page credentials evidence was accepted with an unrelated trace.');
        } catch (OcpiException $error) {
            $this->assertSame('Partner credentials request failed; private transport details are withheld.', $error->getMessage());
            $this->assertStringNotContainsString('SYNTHETIC', $error->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_credential_links', 0);
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('ocpi_roaming_commands', 0);
    }

    public function test_credentials_discovery_rejects_invalid_peer_envelope_dates_without_followup_requests(): void
    {
        $provider = $this->fixture();
        $time = 'yesterday';
        Http::fake(function ($request) use (&$time) {
            $reply = $this->reply($request->url());
            $reply['timestamp'] = $time;

            return Http::response($reply, 200, [
                'X-Request-ID' => $request->header('X-Request-ID')[0],
                'X-Correlation-ID' => $request->header('X-Correlation-ID')[0],
            ]);
        });
        foreach (['yesterday', '2025-02-30T08:00:00Z', '2025-03-10 08:00:00', '2025-03-10T08:00:00+00:00'] as $time) {
            try {
                app(CredentialExchange::class)->endpoint($provider, base64_encode('SYNTHETIC-NON-LIVE-BOOTSTRAP'));
                $this->fail('Malformed credentials response timestamp was accepted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('credentials request failed', $error->getMessage());
            }
        }
        Http::assertSentCount(4);
        $this->assertDatabaseCount('ocpi_credential_links', 0);
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
    }

    public function test_credentials_discovery_accepts_ocpi_implicit_utc_envelope(): void
    {
        $provider = $this->fixture();
        Http::fake(function ($request) {
            $reply = $this->reply($request->url());
            $reply['timestamp'] = '2025-03-10T08:00:00';

            return Http::response($reply, 200, [
                'X-Request-ID' => $request->header('X-Request-ID')[0],
                'X-Correlation-ID' => $request->header('X-Correlation-ID')[0],
            ]);
        });
        $url = app(CredentialExchange::class)->endpoint($provider, base64_encode('SYNTHETIC-NON-LIVE-BOOTSTRAP'));
        $this->assertSame('https://partner.example/ocpi/2.2.1/credentials', $url);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
    }

    public function test_production_mode_requires_both_peer_credential_trace_echoes(): void
    {
        $provider = $this->fixture();
        $this->approveTechnicalAgreement($provider);
        $provider->refresh()->update(['authorized' => true]);
        Http::fake(fn ($request) => Http::response($this->reply($request->url())));
        $original = app()->environment();
        try {
            app()->detectEnvironment(static fn () => 'production');
            try {
                app(CredentialExchange::class)->endpoint($provider, base64_encode('SYNTHETIC-NON-LIVE-BOOTSTRAP'));
                $this->fail('A headerless production peer was accepted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('credentials request failed', $error->getMessage());
            }
        } finally {
            app()->detectEnvironment(static fn () => $original);
        }

        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
    }
}
