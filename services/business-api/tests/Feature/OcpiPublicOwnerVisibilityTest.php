<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Public OCPI discovery is separate from durable owner/private source evidence. */
class OcpiPublicOwnerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer']);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'name' => 'Suspension test operator',
            'connection_key' => 'visibility-fixture', 'authorized' => true, 'version' => '2.2.1',
        ]);
        $location = OcpiRecord::create([
            'ocpi_provider_id' => $provider->id, 'module' => 'locations',
            'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'visibility-test',
            'payload' => ['fixture_only' => true],
            'content_hash' => str_repeat('a', 64),
            'normalized' => [
                'country_code' => 'AE', 'party_id' => 'TST', 'id' => 'visibility-test',
                'name' => 'Visibility fixture', 'address' => 'TEST public road',
                'city' => 'Dubai', 'country' => 'ARE', 'publish' => true,
                'latitude' => '25.2', 'longitude' => '55.3',
                'connector_standards' => ['IEC_62196_T2_COMBO'], 'evses' => [],
            ],
            'source_updated_at' => now()->subMinute(), 'received_at' => now(),
        ]);

        return compact('owner', 'customer', 'provider', 'location');
    }

    private function publicPages(int $count): void
    {
        $this->getJson('/api/roaming/locations')->assertOk()->assertJsonPath('total', $count);
        $this->getJson('/api/roaming/locations?provider=Suspension')->assertOk()->assertJsonPath('total', $count);
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $route) {
            $this->getJson($route.'?scope=partner')->assertOk()->assertJsonPath('total', $count);
            $this->getJson($route.'?scope=partner&operator=Suspension')->assertOk()->assertJsonPath('total', $count);
        }
    }

    public function test_suspending_the_provider_owner_hides_both_discovery_catalogs_and_redacts_private_favorites(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer']);
        $favoriteUrl = '/api/roaming/favorites/'.$f['location']->id;
        $this->publicPages(1);
        $this->putJson($favoriteUrl)->assertOk();
        $this->getJson('/api/roaming/favorites')->assertJsonPath('data.0.location_available', true);

        $f['owner']->forceFill(['account_status' => 'suspended'])->save();
        $this->publicPages(0);
        $this->getJson('/api/roaming/favorites')->assertOk()
            ->assertJsonPath('data.0.location_available', false)
            ->assertJsonPath('data.0.location', null)
            ->assertJsonPath('data.0.provider_label', null)
            ->assertJsonPath('data.0.source_updated_at', null)
            ->assertDontSee('TEST public road');
        $this->putJson($favoriteUrl)->assertNotFound();
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('partner_location_favorites', 1);

        $this->deleteJson($favoriteUrl)->assertOk();
        $this->assertDatabaseCount('partner_location_favorites', 0);
        Http::assertNothingSent();
    }

    public function test_changed_owner_role_is_not_public_approval_and_reactivation_restores_read_only_catalog(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer']);
        $this->publicPages(1);
        $f['owner']->forceFill(['role' => 'admin'])->save();
        $this->publicPages(0);
        $this->putJson('/api/roaming/favorites/'.$f['location']->id)->assertNotFound();

        $f['owner']->forceFill(['role' => 'vendor', 'account_status' => 'active'])->save();
        $this->publicPages(1);
        $this->putJson('/api/roaming/favorites/'.$f['location']->id)->assertOk();
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('partner_location_favorites', 1);
        Http::assertNothingSent();
    }
}
