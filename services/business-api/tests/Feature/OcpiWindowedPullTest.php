<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiWindowedPullTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $url = 'https://partner.example/locations'): OcpiProvider
    {
        Http::preventStrayRequests();
        config(['ocpi.connections.windowed-read-fixture' => [
            'origin' => 'https://partner.example',
            'authorization_token' => base64_encode('NOT-A-LIVE-PARTNER-CREDENTIAL'),
            'environment' => 'sandbox',
        ]]);

        return OcpiProvider::create([
            'owner_id' => User::factory()->create(['role' => 'vendor'])->id,
            'name' => 'Read-only source window fixture',
            'connection_key' => 'windowed-read-fixture',
            'authorized' => true,
            'version' => '2.2.1',
            'endpoints' => ['locations' => $url],
        ]);
    }

    private function envelope(array $records): array
    {
        return ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $records];
    }

    public function test_date_windows_are_utc_normalized_and_preserve_existing_read_only_limits(): void
    {
        $provider = $this->provider();
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response($this->envelope([['id' => 'window-fixture']]));
        });

        $records = app(PartnerClient::class)->pull($provider, 'locations', [
            'date_from' => '2025-03-10T10:00:00+02:00',
            'date_to' => '2025-03-10T10:30:00+02:00',
        ]);
        $this->assertCount(1, $records);
        $this->assertSame('window-fixture', $records[0]['id']);
        $this->assertCount(1, $seen);
        parse_str((string) parse_url($seen[0], PHP_URL_QUERY), $query);
        $this->assertSame('2025-03-10T08:00:00.000000Z', $query['date_from']);
        $this->assertSame('2025-03-10T08:30:00.000000Z', $query['date_to']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_same_window_next_link_keeps_both_pages_without_retrying(): void
    {
        $provider = $this->provider();
        $count = 0;
        Http::fake(function ($request) use (&$count) {
            $count++;
            if ($count === 1) {
                return Http::response($this->envelope([['id' => 'one']]), 200, [
                    'Link' => '<'.$request->url().'&page=2>; rel="next"',
                ]);
            }

            return Http::response($this->envelope([['id' => 'two']]));
        });

        $records = app(PartnerClient::class)->pull($provider, 'locations', [
            'date_from' => '2025-03-10T08:00:00Z',
            'date_to' => '2025-03-10T08:30:00Z',
        ]);
        $this->assertSame(['one', 'two'], array_column($records, 'id'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_partner_next_link_cannot_drop_change_or_duplicate_explicit_source_bounds(): void
    {
        $provider = $this->provider();
        $mode = 'drop';
        Http::fake(function ($request) use (&$mode) {
            $base = $request->url();
            $link = match ($mode) {
                'drop' => 'https://partner.example/locations?page=2',
                'change' => str_replace('08%3A30', '09%3A30', $base).'&page=2',
                'duplicate' => $base.'&date_from=2025-03-10T08%3A00%3A00.000000Z&page=2',
            };

            return Http::response($this->envelope([['id' => 'only-first-page']]), 200, [
                'Link' => '<'.$link.'>; rel="next"',
            ]);
        });
        foreach (['drop', 'change', 'duplicate'] as $mode) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations', [
                    'date_from' => '2025-03-10T08:00:00Z',
                    'date_to' => '2025-03-10T08:30:00Z',
                ]);
                $this->fail('Source-window pagination escaped its requested bounds.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('window', $error->getMessage());
            }
        }
        Http::assertSentCount(3);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_invalid_read_window_is_rejected_without_any_partner_request(): void
    {
        $provider = $this->provider();
        Http::fake();
        foreach ([
            ['date_from' => '2025-03-10 08:00:00'],
            ['date_from' => 'yesterday'],
            ['date_from' => '2025-02-30T08:00:00Z'],
            ['date_to' => '2025-03-10T08:00:00.1234567Z'],
            ['date_from' => '2025-03-10T08:30:00Z', 'date_to' => '2025-03-10T08:30:00Z'],
            ['date_from' => '2025-03-10T09:00:00Z', 'date_to' => '2025-03-10T08:30:00Z'],
            ['date_from' => ['invalid']],
            ['offset' => 5],
        ] as $invalid) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations', $invalid);
                $this->fail('Invalid or ambiguous OCPI source date window was accepted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('window', $error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_negotiated_endpoint_with_existing_date_window_cannot_be_overwritten(): void
    {
        $provider = $this->provider('https://partner.example/locations?date_from=2024-01-01T00%3A00%3A00Z');
        Http::fake();
        try {
            app(PartnerClient::class)->pull($provider, 'locations', ['date_from' => '2025-03-10T08:00:00Z']);
            $this->fail('An existing negotiated source bound was silently replaced.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('window', $error->getMessage());
        }
        Http::assertNothingSent();
    }
}
