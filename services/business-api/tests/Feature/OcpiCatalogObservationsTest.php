<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CatalogObservations;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OcpiCatalogObservationsTest extends TestCase
{
    use RefreshDatabase;

    private function provider(User $owner): OcpiProvider
    {
        return OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Private catalog operator', 'connection_key' => 'PRIVATE CONNECTION KEY', 'authorized' => false]);
    }

    private function record(OcpiProvider $p, string $id, string $module, string $time, array $normalized = []): OcpiRecord
    {
        return OcpiRecord::create(['ocpi_provider_id' => $p->id, 'module' => $module, 'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => $id, 'payload' => ['private_payload' => 'PRIVATE SOURCE DATA'], 'normalized' => $normalized, 'content_hash' => hash('sha256', $id), 'source_updated_at' => $time, 'received_at' => now()]);
    }

    public function test_catalog_age_coordinates_and_retirement_are_observations_only(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T10:00:00.000000Z'));
        Http::fake();
        $owner = User::factory()->create(['role' => 'vendor']);
        $p = $this->provider($owner);
        $this->record($p, 'recent', 'locations', now()->subHour()->toISOString(), ['publish' => true, 'latitude' => '0', 'longitude' => '0', 'connector_standards' => ['IEC_62196_T2']]);
        $this->record($p, 'old', 'locations', now()->subDays(8)->toISOString(), ['publish' => false, 'latitude' => '999', 'longitude' => '55', 'connector_standards' => []]);
        $this->record($p, 'ahead', 'locations', now()->addMicrosecond()->toISOString(), ['latitude' => true, 'longitude' => '55']);
        $tariff = $this->record($p, 'retired', 'tariffs', now()->subHours(2)->toISOString());
        DB::table('ocpi_catalog_retirements')->insert(['record_id' => $tariff->id, 'content_hash' => $tariff->content_hash, 'source_updated_at' => now(), 'retired_at' => now(), 'credential_generation' => 1, 'payload_encrypted' => Crypt::encryptString('{}')]);
        $current = $this->record($p, 'new-version', 'tariffs', now()->subDays(2)->toISOString());
        DB::table('ocpi_catalog_retirements')->insert(['record_id' => $current->id, 'content_hash' => hash('sha256', 'old-version'), 'source_updated_at' => now(), 'retired_at' => now(), 'credential_generation' => 1, 'payload_encrypted' => Crypt::encryptString('{}')]);
        $this->record($p, 'private-session', 'sessions', now()->toISOString(), ['private_user' => 'DO NOT READ']);
        $response = $this->actingAs($owner)->getJson('/api/ocpi/providers/'.$p->id.'/catalog-observations')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('modules.locations.total', 3)->assertJsonPath('modules.tariffs.total', 2)
            ->assertJsonPath('modules.locations.reported_age.within_1_hour', 1)->assertJsonPath('modules.locations.reported_age.older_than_7_days', 1)->assertJsonPath('modules.locations.reported_age.clock_ahead', 1)
            ->assertJsonPath('modules.locations.receipt_age.within_1_hour', 3)->assertJsonPath('modules.locations.coordinates_usable', 1)->assertJsonPath('modules.locations.coordinates_unknown_or_invalid', 2)
            ->assertJsonPath('modules.locations.stored_publish_true', 1)->assertJsonPath('modules.locations.stored_publish_false', 1)->assertJsonPath('modules.locations.stored_publish_unknown', 1)
            ->assertJsonPath('modules.tariffs.current_version_retired', 1)->assertJsonPath('provider.authorized', false)->assertJsonPath('physical_charging_verified', false)->assertJsonPath('remote_control', false);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertStringNotContainsString('DO NOT READ', $response->getContent());
        $this->getJson('/api/ocpi/providers/'.$p->id.'/catalog-observations?module=tariffs')->assertOk()->assertJsonMissingPath('modules.locations');
        $this->getJson('/api/ocpi/providers/'.$p->id.'/catalog-observations?module=sessions')->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_published_reported_market_coverage_uses_location_country_only_and_never_claims_live_capacity(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $provider = $this->provider($owner);
        // Every fixture has the same OCPI party country (AE), which must
        // never be mistaken for the location's actual reported market.
        $this->record($provider, 'uae-published', 'locations', now()->toISOString(), [
            'publish' => true, 'country' => 'ARE', 'latitude' => '25.1', 'longitude' => '55.2',
            'connector_standards' => ['IEC_62196_T2'],
        ]);
        $this->record($provider, 'usa-published', 'locations', now()->toISOString(), [
            'publish' => true, 'country' => 'USA', 'latitude' => '40.7', 'longitude' => '-74',
            'connector_standards' => ['IEC_62196_T2_COMBO'],
        ]);
        $this->record($provider, 'canada-bad-geometry', 'locations', now()->toISOString(), [
            'publish' => true, 'country' => 'CAN', 'latitude' => '999', 'longitude' => '-79',
            'connector_standards' => [],
        ]);
        $this->record($provider, 'uae-unpublished', 'locations', now()->toISOString(), [
            'publish' => false, 'country' => 'ARE', 'latitude' => '26', 'longitude' => '54',
            'connector_standards' => ['IEC_62196_T2'],
        ]);
        $this->record($provider, 'unexpected-partner-country', 'locations', now()->toISOString(), [
            'publish' => true, 'country' => 'ZZZ', 'latitude' => '0', 'longitude' => '0',
            'connector_standards' => [],
        ]);
        $this->record($provider, 'missing-country', 'locations', now()->toISOString(), [
            'publish' => true, 'latitude' => '0', 'longitude' => '0',
            'connector_standards' => [],
        ]);
        $url = '/api/ocpi/providers/'.$provider->id.'/catalog-observations?module=locations';
        $report = $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertJsonPath('modules.locations.total', 6)
            ->assertJsonPath('modules.locations.published_by_reported_market.AE', 1)
            ->assertJsonPath('modules.locations.published_by_reported_market.US', 1)
            ->assertJsonPath('modules.locations.published_by_reported_market.CA', 1)
            ->assertJsonPath('modules.locations.published_by_reported_market.other_or_unknown', 2)
            ->assertJsonPath('modules.locations.published_with_usable_coordinates_by_reported_market.AE', 1)
            ->assertJsonPath('modules.locations.published_with_usable_coordinates_by_reported_market.US', 1)
            ->assertJsonPath('modules.locations.published_with_usable_coordinates_by_reported_market.CA', 0)
            ->assertJsonPath('modules.locations.published_with_usable_coordinates_by_reported_market.other_or_unknown', 2)
            ->assertJsonPath('market_classification', 'PARTNER_REPORTED_LOCATION_COUNTRY_ONLY')
            ->assertJsonPath('coverage_exhaustive', false)
            ->assertJsonPath('live_availability_verified', false)
            ->assertJsonPath('physical_charging_verified', false)
            ->assertJsonMissingPath('modules.tariffs');
        $this->assertStringNotContainsString('PRIVATE SOURCE DATA', $report->getContent());
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_catalog_observations_are_current_owner_or_admin_only(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $p = $this->provider($owner);
        $path = '/api/ocpi/providers/'.$p->id.'/catalog-observations';
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($path)->assertNotFound();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson($path)->assertOk();
        DB::table('users')->where('id', $owner->id)->increment('auth_epoch');
        try {
            app(CatalogObservations::class)->read($owner, $p, 'all');
            $this->fail('Stale owner authority cannot inspect catalog observations.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $token = $admin->createToken('forbidden-admin-mobile')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/ocpi/providers/'.$p->id.'/catalog-observations')->assertForbidden();
    }

    public function test_large_catalog_returns_no_partial_counts_and_one_module_can_be_selected(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $p = $this->provider($owner);
        $payload = Crypt::encryptString('[]');
        foreach (array_chunk(range(1, CatalogObservations::LIMIT + 1), 500) as $ids) {
            DB::table('ocpi_records')->insert(array_map(fn ($id) => ['ocpi_provider_id' => $p->id, 'module' => 'locations', 'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => (string) $id, 'payload' => $payload, 'normalized' => '{}', 'content_hash' => hash('sha256', (string) $id), 'source_updated_at' => now(), 'received_at' => now()], $ids));
        }
        $path = '/api/ocpi/providers/'.$p->id.'/catalog-observations';
        $this->actingAs($owner)->getJson($path)->assertUnprocessable()->assertJsonMissingPath('modules');
        $this->getJson($path.'?module=tariffs')->assertOk()->assertJsonPath('modules.tariffs.total', 0);
    }
}
