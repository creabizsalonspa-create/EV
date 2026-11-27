<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LicensedAddressAccessFiltersTest extends TestCase
{
    public function test_reported_address_and_access_filters_are_literal_cache_only_and_source_qualified(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'discovery.ocm_enabled' => true,
            'discovery.ocm_terms_accepted' => true,
            'discovery.ocm_api_key' => 'synthetic-address-access-key',
        ]);

        $first = [
            'ID' => 12345,
            'DataProvider' => ['Title' => 'Synthetic contributor', 'License' => 'CC BY-SA 4.0', 'IsOpenDataLicensed' => true],
            'SubmissionStatusTypeID' => 200,
            'AddressInfo' => [
                'Title' => 'First site', 'AddressLine1' => 'Énergie Street',
                'Town' => 'Dubai', 'Latitude' => 25.2, 'Longitude' => 55.3,
                'Country' => ['ISOCode' => 'AE'],
            ],
            'UsageType' => ['Title' => 'Membership Required', 'IsMembershipRequired' => true],
            'Connections' => [['ConnectionType' => ['Title' => 'Type 2'], 'PowerKW' => 22]],
        ];
        $second = $first;
        $second['ID'] = 12346;
        $second['AddressInfo']['Town'] = 'Sharjah';
        $second['UsageType'] = ['Title' => 'Public'];
        $unknown = $first;
        $unknown['ID'] = 12347;
        unset($unknown['UsageType']);

        Http::fake(['api.openchargemap.io/*' => Http::response([$first, $second, $unknown], 200, ['Content-Type' => 'application/json'])]);
        $input = ['consent' => true, 'latitude' => 25.2, 'longitude' => 55.3, 'radius_km' => 10];
        $this->postJson('/api/discovery/open-charge-map/search', $input)->assertOk()->assertJsonPath('count', 3);
        foreach (['/api/discovery/open-charge-map/search', '/api/v1/discovery/open-charge-map/search'] as $endpoint) {
            $this->postJson($endpoint, $input + ['address_contains' => 'éNERGIE', 'access_contains' => 'membership'])
                ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.id', 'ocm:12345')
                ->assertJsonPath('results.0.availability', 'unknown')->assertJsonPath('results.0.reservable', false)
                ->assertJsonPath('results.0.remote_control', false)->assertJsonPath('results.0.billing_eligible', false);
            $this->postJson($endpoint, $input + ['address_contains' => 'sharjah'])
                ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.id', 'ocm:12346');
            $this->postJson($endpoint, $input + ['address_contains' => 'Dubai', 'access_contains' => 'Public'])
                ->assertOk()->assertJsonPath('count', 0);
            $this->postJson($endpoint, $input + ['access_contains' => '.*'])
                ->assertOk()->assertJsonPath('count', 0);
            $this->postJson($endpoint, $input + ['address_contains' => str_repeat('x', 151)])
                ->assertUnprocessable();
            $this->postJson($endpoint, $input + ['access_contains' => str_repeat('x', 121)])
                ->assertUnprocessable();
        }
        Http::assertSentCount(1);
    }
}
