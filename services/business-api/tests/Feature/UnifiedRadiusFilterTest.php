<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class UnifiedRadiusFilterTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(float $latitude, float $longitude): array
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create([
            'vendor_id' => $owner->id, 'name' => 'Published radius fixture', 'source' => 'discovery',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => $latitude, 'longitude' => $longitude, 'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'name' => 'Synthetic radius source',
            'connection_key' => 'radius-private-fixture', 'authorized' => true, 'version' => '2.2.1',
        ]);
        $record = OcpiRecord::create([
            'ocpi_provider_id' => $provider->id, 'module' => 'locations',
            'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'radius-partner',
            'payload' => ['secret' => 'NEVER-PROJECT-RADIUS-PRIVATE'], 'content_hash' => str_repeat('a', 64),
            'normalized' => ['publish' => true, 'name' => 'Partner radius fixture', 'country' => 'ARE',
                'latitude' => (string) $latitude, 'longitude' => (string) $longitude],
            'source_updated_at' => now(), 'received_at' => now(),
        ]);

        return [$station, $record, $provider];
    }

    public function test_radius_filters_both_sources_before_pagination_and_preserves_authority(): void
    {
        [$station, $record, $provider] = $this->fixture(25, 55);
        // These are within the square bounding box but outside a ten km circle.
        $outside = $station->replicate();
        $outside->latitude = 25.08;
        $outside->longitude = 55.08;
        $outside->save();
        $partnerOutside = $record->replicate();
        $partnerOutside->external_id = 'outside-circle';
        $partnerOutside->normalized = array_replace($record->normalized, ['latitude' => '25.08', 'longitude' => '55.08']);
        $partnerOutside->save();
        foreach (range(1, 26) as $i) {
            $copy = $station->replicate();
            $copy->latitude = 40;
            $copy->longitude = 70;
            $copy->save();
        }
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $query = '?latitude=25&longitude=55&radius_km=10';
            $response = $this->getJson($path.$query)->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data')
                ->assertDontSee('NEVER-PROJECT-RADIUS-PRIVATE');
            $this->assertEqualsCanonicalizing(['catalog:'.$station->id, 'partner:'.$record->id], array_column($response->json('data'), 'key'));
            $this->getJson($path.$query.'&scope=partner')->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false)
                ->assertJsonPath('data.0.reservable', false);
            $this->getJson($path.$query.'&scope=catalog')->assertOk()->assertJsonPath('total', 1);
            $this->getJson($path.$query.'&page=2')->assertOk()->assertJsonPath('total', 2)->assertJsonCount(0, 'data');
        }
        $provider->update(['authorized' => false]);
        $this->getJson('/api/discovery/search?latitude=25&longitude=55&radius_km=10')->assertOk()->assertJsonPath('total', 1);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_radius_wraps_the_date_line_and_rejects_malformed_partner_coordinates(): void
    {
        [$station, $record] = $this->fixture(10, -179.98);
        $record->update(['normalized' => array_replace($record->normalized, ['longitude' => '179.98'])]);
        foreach ([['longitude' => true], ['latitude' => 'NaN'], ['longitude' => '360'], ['latitude' => ['10']], ['latitude' => null]] as $i => $change) {
            $copy = $record->replicate();
            $copy->external_id = 'invalid-radius-'.$i;
            $copy->normalized = array_replace($record->normalized, $change);
            $copy->save();
        }
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $this->getJson($path.'?latitude=10&longitude=180&radius_km=10')->assertOk()->assertJsonPath('total', 2);
            $this->getJson($path.'?latitude=10&longitude=0&radius_km=10')->assertOk()->assertJsonPath('total', 0);
        }
        // Opposite longitude is still nearby at the pole.
        $station->update(['latitude' => 89.99, 'longitude' => -170]);
        $record->update(['normalized' => array_replace($record->normalized, ['latitude' => '89.99', 'longitude' => '170'])]);
        $this->getJson('/api/discovery/search?latitude=90&longitude=0&radius_km=5')->assertOk()->assertJsonPath('total', 2);
        Http::assertNothingSent();
    }

    public function test_radius_only_admits_explicitly_published_partner_rows_before_pagination(): void
    {
        [$station, $record] = $this->fixture(25, 55);
        foreach ([false, null, 1, 1.0, '1', 'true', [true], ['value' => true]] as $index => $publication) {
            $copy = $record->replicate();
            $copy->external_id = 'unpublished-radius-'.$index;
            $copy->normalized = array_replace($record->normalized, ['publish' => $publication]);
            $copy->save();
        }
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $response = $this->getJson($path.'?latitude=25&longitude=55&radius_km=10')
                ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');
            $this->assertEqualsCanonicalizing(['catalog:'.$station->id, 'partner:'.$record->id], array_column($response->json('data'), 'key'));
        }
        $record->update(['normalized' => array_replace($record->normalized, ['publish' => 'true'])]);
        $this->getJson('/api/discovery/search?latitude=25&longitude=55&radius_km=10')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'catalog');
        Http::assertNothingSent();
    }

    public function test_radius_requires_complete_finite_bounded_input(): void
    {
        $this->fixture(25, 55);
        foreach (['latitude=25', 'latitude=25&longitude=55', 'radius_km=10',
            'latitude=91&longitude=55&radius_km=10', 'latitude=25&longitude=181&radius_km=10',
            'latitude=25&longitude=55&radius_km=0.9', 'latitude=25&longitude=55&radius_km=201',
            'latitude=NaN&longitude=55&radius_km=10', 'latitude[]=25&longitude=55&radius_km=10'] as $query) {
            foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
                $this->getJson($path.'?'.$query)->assertUnprocessable();
            }
        }
        Http::assertNothingSent();
    }
}
