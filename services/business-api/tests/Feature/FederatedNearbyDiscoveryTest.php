<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FederatedNearbyDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'discovery.ocm_enabled' => true,
            'discovery.ocm_terms_accepted' => true,
            'discovery.ocm_api_key' => 'fixture-federated-private-key',
        ]);
    }

    private function input(): array
    {
        return ['latitude' => 25.2, 'longitude' => 55.3, 'radius_km' => 10, 'country' => 'AE', 'consent' => true];
    }

    private function fixture(): User
    {
        $actor = User::factory()->create(['role' => 'customer']);
        $owner = User::factory()->create(['role' => 'vendor']);
        Station::create([
            'vendor_id' => $owner->id, 'name' => 'Published nearby fixture',
            'source' => 'discovery', 'country' => 'AE', 'currency' => 'AED',
            'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3,
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'name' => 'Partner fixture',
            'connection_key' => 'federated-fixture-private', 'authorized' => true, 'version' => '2.2.1',
        ]);
        OcpiRecord::create([
            'ocpi_provider_id' => $provider->id, 'module' => 'locations',
            'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'nearby',
            'payload' => ['secret' => 'NEVER-RETURN-FEDERATED-PRIVATE'],
            'content_hash' => str_repeat('a', 64),
            'normalized' => ['name' => 'Partner nearby fixture', 'publish' => true, 'country' => 'ARE',
                'latitude' => '25.2', 'longitude' => '55.3'],
            'source_updated_at' => now(), 'received_at' => now(),
        ]);

        return $actor;
    }

    public function test_shared_query_keeps_published_and_licensed_evidence_separate_on_both_mounts(): void
    {
        $this->actingAs($this->fixture());
        Http::fake(['api.openchargemap.io/*' => Http::response([], 200, ['Content-Type' => 'application/json'])]);
        foreach (['/api/discovery/federated-nearby', '/api/v1/discovery/federated-nearby'] as $url) {
            $response = $this->postJson($url, $this->input())->assertOk()
                ->assertJsonPath('published.total', 2)->assertJsonCount(2, 'published.data')
                ->assertJsonPath('licensed.state', 'observed')
                ->assertJsonPath('licensed.sample.count', 0)
                ->assertJsonPath('licensed.sample.availability', 'unknown')
                ->assertJsonPath('complete_geographic_coverage', false)
                ->assertJsonPath('live_status_verified', false)
                ->assertJsonPath('combined_total', null)
                ->assertJsonMissingPath('published.next_page_url')
                ->assertDontSee('NEVER-RETURN-FEDERATED-PRIVATE')
                ->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertEqualsCanonicalizing(['catalog', 'partner'], array_column($response->json('published.data'), 'kind'));
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_missing_consent_unsupported_filters_and_invalid_input_never_dispatch(): void
    {
        $url = '/api/discovery/federated-nearby';
        $this->postJson($url, $this->input())->assertUnauthorized();
        $this->actingAs($this->fixture());
        foreach ([
            ['consent' => false], ['consent' => null], ['radius_km' => 201],
            ['latitude' => 91], ['country' => 'XX'], ['page' => 2], ['max_rate_per_kwh' => 1],
        ] as $change) {
            $this->postJson($url, array_replace($this->input(), $change))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_provider_unavailable_is_not_empty_coverage_and_does_not_erase_local_results(): void
    {
        $this->actingAs($this->fixture());
        config(['discovery.ocm_terms_accepted' => false]);
        $this->postJson('/api/discovery/federated-nearby', $this->input())->assertOk()
            ->assertJsonPath('published.total', 2)->assertJsonPath('licensed.state', 'unavailable')
            ->assertJsonPath('licensed.reason', 'not_configured')->assertJsonPath('licensed.sample', null);
        Http::assertNothingSent();
    }

    public function test_partner_revocation_during_source_fetch_is_reflected_in_published_page(): void
    {
        $this->actingAs($this->fixture());
        Http::fake(['api.openchargemap.io/*' => function () {
            OcpiProvider::where('connection_key', 'federated-fixture-private')->update(['authorized' => false]);

            return Http::response([[
                'ID' => 12345,
                'DataProvider' => ['Title' => 'Fixture licensor', 'License' => 'CC BY-SA 4.0', 'IsOpenDataLicensed' => true],
                'SubmissionStatusTypeID' => 200,
                'AddressInfo' => ['Title' => 'Licensed nearby fixture', 'Latitude' => 25.2, 'Longitude' => 55.3, 'Country' => ['ISOCode' => 'AE']],
                'Connections' => [['ConnectionType' => ['Title' => 'Type 2'], 'PowerKW' => 22]],
            ]], 200, ['Content-Type' => 'application/json']);
        }]);
        $this->postJson('/api/discovery/federated-nearby', $this->input())->assertOk()
            ->assertJsonPath('published.total', 1)
            ->assertJsonPath('published.data.0.kind', 'catalog')
            ->assertJsonPath('licensed.sample.count', 1)
            ->assertJsonPath('licensed.sample.results.0.id', 'ocm:12345')
            ->assertJsonPath('licensed.sample.results.0.attribution', 'Open Charge Map / Fixture licensor')
            ->assertJsonPath('licensed.sample.results.0.availability', 'unknown')
            ->assertJsonPath('licensed.sample.results.0.remote_control', false)
            ->assertJsonPath('licensed.sample.results.0.reservable', false)
            ->assertJsonPath('licensed.sample.results.0.billing_eligible', false);
        Http::assertSentCount(1);
    }

    public function test_license_revocation_during_source_fetch_does_not_release_reports(): void
    {
        $this->actingAs($this->fixture());
        Http::fake(['api.openchargemap.io/*' => function () {
            config(['discovery.ocm_terms_accepted' => false]);

            return Http::response([], 200, ['Content-Type' => 'application/json']);
        }]);
        $this->postJson('/api/discovery/federated-nearby', $this->input())->assertOk()
            ->assertJsonPath('published.total', 2)->assertJsonPath('licensed.state', 'unavailable')
            ->assertJsonPath('licensed.reason', 'not_configured')->assertJsonPath('licensed.sample', null);
        Http::assertSentCount(1);
    }

    public function test_account_change_during_provider_response_discards_both_sources(): void
    {
        $actor = $this->fixture();
        $this->actingAs($actor);
        Http::fake(['api.openchargemap.io/*' => function () use ($actor) {
            DB::table('users')->where('id', $actor->id)->increment('auth_epoch');

            return Http::response([], 200, ['Content-Type' => 'application/json']);
        }]);
        $this->postJson('/api/discovery/federated-nearby', $this->input())->assertConflict()
            ->assertJsonMissingPath('published')->assertJsonMissingPath('licensed');
        Http::assertSentCount(1);
    }
}
