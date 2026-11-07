<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Discovery\PartnerCatalogGeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerLocationDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function provider(bool $authorized = true): OcpiProvider
    {
        return OcpiProvider::create([
            'owner_id' => User::factory()->create(['role' => 'vendor'])->id,
            'name' => 'Synthetic operator',
            'connection_key' => 'fixture-'.bin2hex(random_bytes(4)),
            'authorized' => $authorized,
            'version' => '2.2.1',
        ]);
    }

    private function location(
        OcpiProvider $provider,
        string $id,
        float $lat,
        float $lng,
        bool $publish = true,
        string $country = 'ARE',
        string $standard = 'IEC_62196_T2_COMBO'
    ): OcpiRecord {
        return OcpiRecord::create([
            'ocpi_provider_id' => $provider->id,
            'module' => 'locations',
            'country_code' => 'AE',
            'party_id' => 'TST',
            'external_id' => $id,
            'payload' => ['fixture_only' => true],
            'normalized' => [
                'country_code' => 'AE',
                'party_id' => 'TST',
                'id' => $id,
                'name' => 'Fixture '.$id,
                'address' => 'Fixture address',
                'city' => 'Fixture city',
                'country' => $country,
                'latitude' => (string) $lat,
                'longitude' => (string) $lng,
                'publish' => $publish,
                'connector_standards' => [$standard],
                'evses' => [],
                'availability' => 'unknown',
                'remote_control' => false,
                'reservable' => false,
            ],
            'content_hash' => hash('sha256', $id),
            'source_updated_at' => now()->subHours(3),
            'received_at' => now()->subMinute(),
        ]);
    }

    public function test_unchanged_public_listing_preserves_source_authorization_and_truth_labels(): void
    {
        $provider = $this->provider();
        $row = $this->location($provider, 'published', 25.2, 55.3);
        $this->location($provider, 'unpublished', 25.2, 55.3, false);
        $revoked = $this->provider(false);
        $this->location($revoked, 'revoked', 25.2, 55.3);

        $this->getJson('/api/roaming/locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.evidence', 'PARTNER_REPORTED_UNVERIFIED')
            ->assertJsonPath('data.0.availability', 'unknown')
            ->assertJsonPath('data.0.remote_control', false)
            ->assertJsonPath('data.0.reservable', false)
            ->assertJsonPath('data.0.source_timestamp_evidence', 'PARTNER_REPORTED_ONLY')
            ->assertJsonPath('data.0.reported_source_age_minutes', 180)
            ->assertJsonMissingPath('data.0.payload')
            ->assertJsonMissingPath('data.0.content_hash')
            ->assertJsonMissingPath('data.0.distance_km');
    }

    public function test_nearby_query_filters_and_orders_exact_great_circle_distance(): void
    {
        $provider = $this->provider();
        $far = $this->location($provider, 'far', 25.4, 55.3);
        $near = $this->location($provider, 'near', 25.201, 55.3);
        $this->location($provider, 'outside', 25.7, 55.3);
        $response = $this->getJson('/api/roaming/locations?latitude=25.2&longitude=55.3&radius_km=30');
        $response->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $near->id)
            ->assertJsonPath('data.1.id', $far->id)
            ->assertJsonPath('data.0.distance_basis', 'great_circle')
            ->assertJsonPath('data.0.remote_control', false);
        $distances = collect($response->json('data'))->pluck('distance_km')->all();
        $this->assertGreaterThan(0, $distances[0]);
        $this->assertLessThan($distances[1], $distances[0]);
    }

    public function test_nearby_search_wraps_across_international_date_line(): void
    {
        $provider = $this->provider();
        $east = $this->location($provider, 'east', 0.0, 179.99);
        $west = $this->location($provider, 'west', 0.0, -179.99);
        $this->location($provider, 'distant', 0.0, -160.0);
        $result = $this->getJson('/api/roaming/locations?latitude=0&longitude=179.99&radius_km=5')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([$east->id, $west->id], collect($result->json('data'))->pluck('id')->all());
    }

    public function test_polar_cap_does_not_exclude_nearby_records_of_other_longitudes(): void
    {
        $provider = $this->provider();
        $this->location($provider, 'pole-a', 89.99, 10);
        $this->location($provider, 'pole-b', 89.99, -170);
        $this->getJson('/api/roaming/locations?latitude=89.999&longitude=10&radius_km=5')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_nearby_country_connector_and_name_filters_compose(): void
    {
        $provider = $this->provider();
        $match = $this->location($provider, 'match', 25.2, 55.3);
        $this->location($provider, 'other-standard', 25.2, 55.3, true, 'ARE', 'CHADEMO');
        $this->location($provider, 'other-country', 25.2, 55.3, true, 'NLD');
        $result = $this->getJson('/api/roaming/locations?q=match&country=ARE&connector_standard=IEC_62196_T2_COMBO&latitude=25.2&longitude=55.3&radius_km=10')
            ->assertOk()->assertJsonCount(1, 'data');
        $result->assertJsonPath('data.0.id', $match->id);
    }

    public function test_invalid_partial_or_out_of_range_nearby_filter_is_rejected(): void
    {
        foreach ([
            'latitude=25.2',
            'latitude=25.2&longitude=55.3',
            'longitude=55.3&radius_km=10',
            'latitude=91&longitude=55.3&radius_km=10',
            'latitude=25.2&longitude=181&radius_km=10',
            'latitude=25.2&longitude=55.3&radius_km=201',
            'latitude=NaN&longitude=55.3&radius_km=10',
        ] as $query) {
            $this->getJson('/api/roaming/locations?'.$query)->assertUnprocessable();
        }
    }

    public function test_bounds_reject_nonfinite_inputs_and_bound_longitudes(): void
    {
        $bounds = PartnerCatalogGeo::bounds(0, 179.99, 5);
        $this->assertCount(2, $bounds['longitude_ranges']);
        $this->assertSame([[-180.0, 180.0]], PartnerCatalogGeo::bounds(89.999, 10, 5)['longitude_ranges']);
        foreach ([INF, NAN, -INF] as $bad) {
            try {
                PartnerCatalogGeo::bounds($bad, 0, 10);
                $this->fail('Nonfinite discovery input was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_no_unverified_partner_is_published_by_nearby_search(): void
    {
        $revoked = $this->provider(false);
        $this->location($revoked, 'unapproved', 25.2, 55.3);
        $authorized = $this->provider();
        $this->location($authorized, 'hidden', 25.2, 55.3, false);
        $this->getJson('/api/roaming/locations?latitude=25.2&longitude=55.3&radius_km=1')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
