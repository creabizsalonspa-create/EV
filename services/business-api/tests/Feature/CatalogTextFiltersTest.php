<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTextFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function station(string $name, string $operator, string $access): Station
    {
        $vendor = User::factory()->create(['role' => 'vendor', 'name' => $operator]);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => $name, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'discovery', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $this->actingAs($vendor)->putJson('/api/stations/'.$station->id.'/information', [
            'revision' => 0, 'description_en' => null, 'description_ar' => null, 'operator_name' => $operator, 'address_en' => '100%_! Harbour Street', 'address_ar' => 'شارع الميناء', 'access_en' => $access, 'access_ar' => 'دخول الزوار',
            'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['open' => null, 'close' => null]),
            'closed_dates' => [], 'amenities' => [], 'accessibility' => [],
        ])->assertOk();
        $this->app['auth']->forgetGuards();

        return $station;
    }

    public function test_combined_filters_are_applied_before_pagination_and_preserve_capabilities(): void
    {
        $match = $this->station('Harbour', 'Public Operator', 'Visitor gate B');
        for ($i = 0; $i < 26; $i++) {
            $this->station('Harbour '.$i, 'Other Operator', 'Staff gate A');
        }
        foreach (['/api', '/api/v1'] as $prefix) {
            $reply = $this->getJson($prefix.'/stations?'.http_build_query(['q' => 'harbour', 'operator' => 'public', 'access' => 'visitor', 'connector_type' => 'CCS2']))
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $match->id)
                ->assertJsonPath('data.0.remote_control', false)->assertJsonPath('data.0.reservable', false)
                ->assertJsonPath('data.0.availability', 'unknown');
            $this->assertStringNotContainsString('email', $reply->getContent());
        }
        $this->getJson('/api/stations?'.http_build_query(['access' => 'دخول']))->assertJsonPath('total', 27);
        $this->getJson('/api/stations?'.http_build_query(['address' => 'الميناء']))->assertJsonPath('total', 27);
        $this->getJson('/api/stations?'.http_build_query(['address' => '%_!']))->assertJsonPath('total', 27);
        $this->getJson('/api/stations?'.http_build_query(['address' => "' OR 1=1 --"]))->assertJsonPath('total', 0);
    }

    public function test_wildcards_are_literal_in_every_text_filter(): void
    {
        $target = $this->station('100%_! Station', '100%_! Operator', '100%_! Access');
        $this->station('100xxxx Station', '100xxxx Operator', '100xxxx Access');
        foreach (['q', 'operator', 'access'] as $key) {
            $this->getJson('/api/stations?'.http_build_query([$key => '%_!']))
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $target->id);
            $this->getJson('/api/stations?'.http_build_query([$key => "' OR 1=1 --"]))
                ->assertOk()->assertJsonPath('total', 0);
        }
    }

    public function test_filters_do_not_search_private_vendor_identity_and_validate_bounds(): void
    {
        $station = $this->station('Harbour', 'Public Operator', 'Visitors');
        $vendor = User::findOrFail($station->vendor_id);
        $vendor->update(['name' => 'Private account identity']);
        $this->getJson('/api/stations?operator=Private')->assertJsonPath('total', 0);
        $this->getJson('/api/stations?operator=Public')->assertJsonPath('total', 1);
        $this->getJson('/api/stations?'.http_build_query(['operator' => $vendor->email]))->assertJsonPath('total', 0);
        foreach (['q' => 101, 'operator' => 101, 'access' => 151, 'address' => 151] as $key => $length) {
            $this->getJson('/api/stations?'.http_build_query([$key => str_repeat('a', $length)]))->assertUnprocessable();
            $this->getJson('/api/stations?'.$key.'[]=bad')->assertUnprocessable();
        }
        $this->getJson('/api/stations?operator=%20%20&access=%20')->assertJsonPath('total', 1);
    }
}
