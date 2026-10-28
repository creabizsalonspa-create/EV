<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoveryLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['discovery.geocoding_enabled' => true, 'discovery.routing_enabled' => true, 'discovery.geoapify_key' => 'synthetic-private-key']);
    }

    private function address(): array
    {
        return ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['formatted' => '<img src=x> شارع الاختبار'], 'geometry' => ['type' => 'Point', 'coordinates' => [55.3, 25.2]]]]];
    }

    private function routeData(string $type = 'MultiLineString'): array
    {
        $points = [[55.3, 25.2], [55.31, 25.21]];

        return ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['distance' => 1250.5, 'time' => 220, 'distance_units' => 'meters', 'time_units' => 'seconds'], 'geometry' => ['type' => $type, 'coordinates' => $type === 'MultiLineString' ? [$points, [[55.31, 25.21], [55.4, 25.3]]] : $points]]]];
    }

    private function routeInput(): array
    {
        return ['consent' => true, 'language' => 'en', 'points' => [['latitude' => 25.2, 'longitude' => 55.3], ['latitude' => 25.3, 'longitude' => 55.4]]];
    }

    public function test_unconfigured_features_fail_closed_without_provider_calls_or_key_exposure(): void
    {
        config(['discovery.geoapify_key' => null]);
        $this->getJson('/api/discovery/capabilities')->assertOk()->assertJsonPath('geocoding', false)->assertJsonPath('routing', false)->assertJsonMissing(['geoapify_key' => 'synthetic-private-key']);
        $this->postJson('/api/discovery/addresses', ['consent' => true, 'query' => 'Dubai', 'language' => 'en'])->assertStatus(503)->assertJsonPath('reason', 'not_configured');
        $this->postJson('/api/v1/discovery/route', $this->routeInput())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_address_contract_is_bounded_encoded_and_server_only_with_truthful_namespace(): void
    {
        Http::fake(fn () => Http::response($this->address()));
        $response = $this->postJson('/api/discovery/addresses', ['consent' => true, 'query' => 'شارع & دبي', 'language' => 'ar', 'country' => 'AE']);
        $response->assertOk()->assertJsonPath('evidence', 'GEOCODING_RESULT')->assertJsonPath('places.0.latitude', 25.2)->assertJsonPath('places.0.longitude', 55.3)->assertJsonPath('places.0.label', '<img src=x> شارع الاختبار');
        $this->assertStringStartsWith('geoapify:', $response->json('places.0.id'));
        $this->assertStringNotContainsString('synthetic-private-key', $response->getContent());
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.geoapify.com/v1/geocode/autocomplete?') && $r['text'] === 'شارع & دبي' && $r['apiKey'] === 'synthetic-private-key' && $r['filter'] === 'countrycode:ae' && $r['limit'] === 5 && ! $r->hasHeader('Cookie') && ! $r->hasHeader('Authorization'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
    }

    public function test_input_requires_current_consent_supported_language_and_bounded_coordinates(): void
    {
        $this->postJson('/api/discovery/addresses', ['query' => 'Dubai', 'language' => 'en'])->assertUnprocessable();
        $this->postJson('/api/discovery/addresses', ['consent' => false, 'query' => 'Dubai', 'language' => 'en'])->assertUnprocessable();
        $this->postJson('/api/discovery/addresses', ['consent' => true, 'query' => 'xy', 'language' => 'en'])->assertUnprocessable();
        $input = $this->routeInput();
        $input['points'][0]['longitude'] = 181;
        $this->postJson('/api/discovery/route', $input)->assertUnprocessable();
        $input = $this->routeInput();
        $input['points'] = array_fill(0, 6, $input['points'][0]);
        $this->postJson('/api/discovery/route', $input)->assertUnprocessable();
        $input['points'] = [['latitude' => 0, 'longitude' => 0, 'station_id' => 1], ['latitude' => 1, 'longitude' => 1]];
        $this->postJson('/api/discovery/route', $input)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_route_preserves_provider_segments_estimates_and_never_invents_traffic_or_charging_time(): void
    {
        Http::fake(['api.geoapify.com/*' => Http::response($this->routeData())]);
        $this->postJson('/api/v1/discovery/route', $this->routeInput())->assertOk()->assertJsonPath('evidence', 'PROVIDER_ROUTE_ESTIMATE')->assertJsonPath('distance_meters', 1250.5)->assertJsonPath('duration_seconds', 220)->assertJsonPath('traffic_verified', false)->assertJsonPath('charging_time_included', false)->assertJsonCount(2, 'segments')->assertJsonPath('segments.0.0.latitude', 25.2);
        Http::assertSent(fn ($r) => $r['waypoints'] === '25.2,55.3|25.3,55.4' && $r['mode'] === 'drive' && $r['units'] === 'metric');
    }

    public function test_malformed_oversized_redirected_and_provider_failures_are_redacted(): void
    {
        Http::fakeSequence()->push('private key/address in upstream error', 500)->push('', 302, ['Location' => 'http://127.0.0.1/private'])->push(['type' => 'FeatureCollection', 'features' => [['geometry' => ['type' => 'Point', 'coordinates' => [181, 0]], 'properties' => ['formatted' => 'bad']]]])->push(str_repeat('x', 1048577), 200, ['Content-Type' => 'application/json']);
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/discovery/addresses', ['consent' => true, 'query' => 'private home address', 'language' => 'en']);
            $this->assertContains($response->status(), [502, 503]);
            $this->assertStringNotContainsString('private key/address', $response->getContent());
            $this->assertStringNotContainsString('synthetic-private-key', $response->getContent());
        }
        Http::assertSentCount(4);
    }

    public function test_bad_route_units_points_and_no_route_cannot_be_presented_as_valid_estimates(): void
    {
        $bad = $this->routeData();
        $bad['features'][0]['properties']['distance_units'] = 'miles';
        $invalid = $this->routeData();
        $invalid['features'][0]['geometry']['coordinates'][0][0] = [55, 91];
        Http::fakeSequence()->push($bad)->push($invalid)->push(['type' => 'FeatureCollection', 'features' => []]);
        $this->postJson('/api/discovery/route', $this->routeInput())->assertStatus(502);
        $this->postJson('/api/discovery/route', $this->routeInput())->assertStatus(502);
        $this->postJson('/api/discovery/route', $this->routeInput())->assertStatus(422)->assertJsonPath('reason', 'not_found');
    }

    public function test_provider_budget_is_enforced_without_changing_authentication_limits(): void
    {
        config(['discovery.requests_per_minute' => 2]);
        Http::fake(fn () => Http::response($this->address()));
        $input = ['consent' => true, 'query' => 'Dubai', 'language' => 'en'];
        $this->postJson('/api/discovery/addresses', $input)->assertOk();
        $this->postJson('/api/discovery/addresses', $input)->assertOk();
        $this->postJson('/api/discovery/addresses', $input)->assertTooManyRequests();
        Http::assertSentCount(2);
    }
}
