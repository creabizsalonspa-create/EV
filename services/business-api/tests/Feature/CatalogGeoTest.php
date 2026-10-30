<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogGeoTest extends TestCase
{
    use RefreshDatabase;

    private function station(float $lat, float $lon, string $name = 'Nearby'): Station
    {
        return Station::create(['vendor_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => $name, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => $lat, 'longitude' => $lon, 'source' => 'discovery', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    private function nearby(float $lat, float $lon, float $radius = 10, array $filters = [])
    {
        return $this->getJson('/api/stations?'.http_build_query($filters + ['latitude' => $lat, 'longitude' => $lon, 'radius_km' => $radius]));
    }

    public function test_radius_sorts_by_distance_and_preserves_truthful_capabilities(): void
    {
        $same = $this->station(25.2, 55.3);
        $near = $this->station(25.21, 55.3);
        $this->station(26, 55.3);
        $this->nearby(25.2, 55.3)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $same->id)->assertJsonPath('data.1.id', $near->id)
            ->assertJsonPath('data.0.distance_km', 0)->assertJsonPath('data.0.availability', 'unknown')
            ->assertJsonPath('data.0.reservable', false);
        $distance = $this->nearby(25.2, 55.3)->json('data.1.distance_km');
        $this->assertEqualsWithDelta(1.11195, $distance, 0.001);
        $this->getJson('/api/stations')->assertOk()->assertJsonMissingPath('data.0.distance_km');
    }

    public function test_date_line_and_poles_do_not_drop_nearby_stations(): void
    {
        $west = $this->station(0, -179.99);
        $east = $this->station(0, 179.99);
        $pole = $this->station(89.99, -135);
        $this->nearby(0, 180)->assertJsonCount(2, 'data');
        $this->nearby(0, -180)->assertJsonCount(2, 'data');
        $this->nearby(90, 45)->assertJsonPath('data.0.id', $pole->id);
        $this->nearby(0, 180, 1)->assertJsonCount(0, 'data');
        $this->assertNotEquals($west->id, $east->id);
    }

    public function test_nearby_composes_filters_and_paginates_with_stable_ties(): void
    {
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            $ids[] = $this->station(25.2, 55.3, 'Searchable')->id;
        }
        $this->station(25.2, 55.3, 'Excluded');
        $this->nearby(25.2, 55.3, 10, ['q' => 'Searchable', 'connector_type' => 'CCS2', 'min_power_kw' => 50])->assertJsonPath('total', 27)->assertJsonCount(25, 'data')->assertJsonPath('data.0.id', $ids[26]);
        $link = $this->nearby(25.2, 55.3, 10, ['q' => 'Searchable', 'unrelated' => 'omit-me'])->json('next_page_url');
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $this->assertEquals(['q' => 'Searchable', 'latitude' => '25.2', 'longitude' => '55.3', 'radius_km' => '10', 'page' => '2'], $query);
        $this->getJson($link)->assertJsonCount(2, 'data')->assertJsonPath('data.1.id', $ids[0]);
        $this->nearby(25.2, 55.3, 10, ['connector_type' => 'Type2'])->assertJsonCount(0, 'data');
    }

    public function test_partial_invalid_or_unbounded_coordinates_are_rejected(): void
    {
        foreach ([['latitude' => 25], ['longitude' => 55], ['radius_km' => 10], ['latitude' => 91, 'longitude' => 55, 'radius_km' => 10], ['latitude' => 25, 'longitude' => -181, 'radius_km' => 10], ['latitude' => 25, 'longitude' => 55, 'radius_km' => 201], ['latitude' => 'NaN', 'longitude' => 55, 'radius_km' => 10], ['latitude' => 25, 'longitude' => 55, 'radius_km' => 0]] as $query) {
            $this->getJson('/api/stations?'.http_build_query($query))->assertUnprocessable();
        }
    }
}
