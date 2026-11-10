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
            ->assertJsonPath('data.0.provider_label', 'Synthetic operator')
            ->assertJsonPath('data.0.provider_label_evidence', 'PROVIDER_CONFIGURED_UNVERIFIED')
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
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(2, 'data')
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

    public function test_text_search_treats_wildcards_and_escape_signs_as_literal_characters(): void
    {
        $provider = $this->provider();
        $percent = $this->location($provider, 'discount-10%', 25.2, 55.3);
        $this->location($provider, 'discount-10X', 25.2, 55.3);
        $underscore = $this->location($provider, 'under_score', 25.2, 55.3);
        $this->location($provider, 'underXscore', 25.2, 55.3);
        $bang = $this->location($provider, 'value!proof', 25.2, 55.3);

        $this->getJson('/api/roaming/locations?q='.rawurlencode('discount-10%'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $percent->id);
        $this->getJson('/api/roaming/locations?q='.rawurlencode('under_score'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $underscore->id);
        $this->getJson('/api/roaming/locations?q='.rawurlencode('value!proof'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bang->id);
    }

    public function test_configured_provider_filter_is_literal_and_preserves_authorization(): void
    {
        $source = $this->provider();
        $source->update(['name' => 'Network_50%!']);
        $match = $this->location($source, 'network-match', 25.2, 55.3);
        $similarlyNamed = $this->provider();
        $similarlyNamed->update(['name' => 'NetworkX50X!']);
        $this->location($similarlyNamed, 'network-other', 25.2, 55.3);
        $revoked = $this->provider(false);
        $revoked->update(['name' => 'Network_50%!']);
        $this->location($revoked, 'network-revoked', 25.2, 55.3);

        $this->getJson('/api/roaming/locations?provider='.rawurlencode('network_50%!'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.provider_label', 'Network_50%!')
            ->assertJsonPath('data.0.provider_label_evidence', 'PROVIDER_CONFIGURED_UNVERIFIED')
            ->assertJsonMissingPath('data.0.connection_key')
            ->assertJsonMissingPath('data.0.owner_id');

        $this->getJson('/api/roaming/locations?provider='.str_repeat('x', 81))
            ->assertUnprocessable();
    }

    public function test_public_projection_cannot_reveal_future_sensitive_normalized_fields(): void
    {
        $provider = $this->provider();
        $record = $this->location($provider, 'projection', 25.2, 55.3);
        $normalized = $record->normalized;
        $normalized['private_contract'] = 'NEVER_PUBLISH_PARTNER_CONTRACT';
        $normalized['billing_eligible'] = true;
        $normalized['remote_control'] = true;
        $normalized['reservable'] = true;
        $normalized['evses'] = [[
            'uid' => 'evse-1',
            'reported_status' => 'AVAILABLE',
            'operator_secret' => 'NEVER_PUBLISH_EVSE_SECRET',
            'connectors' => [[
                'id' => 'connector-1',
                'standard' => 'IEC_62196_T2_COMBO',
                'private_token' => 'NEVER_PUBLISH_CONNECTOR_TOKEN',
            ]],
        ]];
        $record->normalized = $normalized;
        $record->save();

        $response = $this->getJson('/api/roaming/locations')->assertOk();
        $response->assertJsonPath('data.0.location.evses.0.uid', 'evse-1')
            ->assertJsonPath('data.0.location.evses.0.connectors.0.standard', 'IEC_62196_T2_COMBO')
            ->assertJsonPath('data.0.location.remote_control', false)
            ->assertJsonPath('data.0.location.reservable', false)
            ->assertJsonPath('data.0.location.availability', 'unknown')
            ->assertJsonMissingPath('data.0.location.private_contract')
            ->assertJsonMissingPath('data.0.location.billing_eligible')
            ->assertJsonMissingPath('data.0.location.evses.0.operator_secret')
            ->assertJsonMissingPath('data.0.location.evses.0.connectors.0.private_token');
        $response->assertDontSee('NEVER_PUBLISH_');
    }

    public function test_source_age_filter_rejects_stale_future_and_invalid_values(): void
    {
        $provider = $this->provider();
        $recent = $this->location($provider, 'recent', 25.2, 55.3);
        $old = $this->location($provider, 'old', 25.2, 55.3);
        $old->source_updated_at = now()->subHours(26);
        $old->save();
        $future = $this->location($provider, 'future', 25.2, 55.3);
        $future->source_updated_at = now()->addMinutes(2);
        $future->save();

        $response = $this->getJson('/api/roaming/locations?max_source_age_hours=6');
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id)
            ->assertJsonPath('data.0.reported_source_age_minutes', 180)
            ->assertJsonPath('data.0.source_clock_evidence', 'PARTNER_REPORTED_TIME')
            ->assertJsonPath('data.0.received_timestamp_evidence', 'LOCAL_INGESTION_TIME')
            ->assertJsonPath('data.0.availability', 'unknown')
            ->assertJsonPath('data.0.remote_control', false);

        $this->getJson('/api/roaming/locations?provider=Synthetic&max_source_age_hours=6&latitude=25.2&longitude=55.3&radius_km=5')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recent->id);

        foreach (['0', '169', '1.5', 'NaN'] as $invalid) {
            $this->getJson('/api/roaming/locations?max_source_age_hours='.$invalid)->assertUnprocessable();
        }

        $unfiltered = $this->getJson('/api/roaming/locations')->assertOk();
        $ahead = collect($unfiltered->json('data'))->firstWhere('id', $future->id);
        $this->assertNull($ahead['reported_source_age_minutes']);
        $this->assertSame('PARTNER_CLOCK_AHEAD', $ahead['source_clock_evidence']);
        $this->assertSame('unknown', $ahead['availability']);
    }

    public function test_reported_evse_state_filter_is_explicitly_unverified_and_composes_with_other_filters(): void
    {
        $provider = $this->provider();
        $available = $this->location($provider, 'available-report', 25.2, 55.3);
        $charging = $this->location($provider, 'charging-report', 25.21, 55.3);
        $hidden = $this->location($provider, 'hidden-report', 25.2, 55.3, false);
        foreach ([
            [$available, 'AVAILABLE'], [$charging, 'CHARGING'], [$hidden, 'AVAILABLE'],
        ] as [$record, $state]) {
            $normalized = $record->normalized;
            $normalized['evses'] = [[
                'uid' => 'test-evse',
                'reported_status' => $state,
                'connectors' => [['id' => 'c1', 'standard' => 'IEC_62196_T2_COMBO']],
            ]];
            $record->normalized = $normalized;
            $record->save();
        }
        $response = $this->getJson('/api/roaming/locations?reported_evse_status=AVAILABLE&provider=Synthetic&country=ARE&latitude=25.2&longitude=55.3&radius_km=5');
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id)
            ->assertJsonPath('data.0.location.evses.0.reported_status', 'AVAILABLE')
            ->assertJsonPath('data.0.location.availability', 'unknown')
            ->assertJsonPath('data.0.availability', 'unknown')
            ->assertJsonPath('data.0.remote_control', false)
            ->assertJsonPath('data.0.reservable', false);
        $this->assertStringContainsString('reported_evse_status=AVAILABLE', $response->json('first_page_url'));
        $this->getJson('/api/roaming/locations?reported_evse_status=CHARGING')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $charging->id);
        $this->getJson('/api/roaming/locations?reported_evse_status=AVAILABLE%27%20OR%201%3D1')
            ->assertUnprocessable();
        $this->getJson('/api/roaming/locations?reported_evse_status=available')
            ->assertUnprocessable();
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
