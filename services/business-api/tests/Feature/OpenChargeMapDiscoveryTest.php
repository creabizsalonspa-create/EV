<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenChargeMapDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config([
            'discovery.ocm_enabled' => true,
            'discovery.ocm_terms_accepted' => true,
            'discovery.ocm_api_key' => 'synthetic-private-ocm-key',
        ]);
    }

    private function point(): array
    {
        return [
            'ID' => 12345,
            'DataProvider' => [
                'Title' => 'Licensed contributor',
                'License' => 'CC BY-SA 4.0',
                'IsOpenDataLicensed' => true,
            ],
            'SubmissionStatusTypeID' => 200,
            'StatusType' => ['IsOperational' => true],
            'UsageType' => ['Title' => 'Membership Required', 'IsMembershipRequired' => true, 'IsAccessKeyRequired' => false],
            'AddressInfo' => [
                'Title' => 'Fixture open charge station',
                'AddressLine1' => 'Fixture street',
                'Town' => 'Dubai',
                'Latitude' => 25.2,
                'Longitude' => 55.3,
                'Country' => ['ISOCode' => 'AE'],
            ],
            'Connections' => [['ConnectionType' => ['Title' => 'Type 2'], 'PowerKW' => 22]],
            'SecretAttribute' => 'DO_NOT_LEAK',
        ];
    }

    private function input(): array
    {
        return ['consent' => true, 'latitude' => 25.2, 'longitude' => 55.3, 'radius_km' => 10];
    }

    public function test_disabled_source_is_invisible_to_network_and_never_exposes_key(): void
    {
        config(['discovery.ocm_enabled' => false]);
        $this->getJson('/api/discovery/open-charge-map/capabilities')
            ->assertOk()->assertJsonPath('enabled', false)
            ->assertJsonPath('remote_control', false)
            ->assertJsonMissingPath('ocm_api_key');
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())
            ->assertStatus(503)->assertJsonPath('reason', 'not_configured');
        Http::assertNothingSent();
    }

    public function test_explicit_consent_and_bounded_coordinate_validation(): void
    {
        $missing = $this->input();
        unset($missing['consent']);
        $this->postJson('/api/discovery/open-charge-map/search', $missing)->assertUnprocessable();
        foreach ([['latitude' => 91], ['longitude' => 181], ['radius_km' => 201], ['radius_km' => 0]] as $difference) {
            $this->postJson('/api/discovery/open-charge-map/search', array_replace($this->input(), $difference))
                ->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_approved_licensed_search_is_source_only_with_visible_license_and_no_credentials(): void
    {
        Http::fake(['api.openchargemap.io/*' => Http::response([$this->point()], 200, ['Content-Type' => 'application/json'])]);
        $response = $this->postJson('/api/discovery/open-charge-map/search', $this->input());
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('provider', 'open_charge_map')
            ->assertJsonPath('results.0.id', 'ocm:12345')
            ->assertJsonPath('results.0.data_license', 'CC BY-SA 4.0')
            ->assertJsonPath('results.0.attribution', 'Open Charge Map / Licensed contributor')
            ->assertJsonPath('results.0.availability', 'unknown')
            ->assertJsonPath('results.0.reported_operational', true)
            ->assertJsonPath('results.0.reported_operational_evidence', 'OPEN_CHARGE_MAP_CROWD_STATUS_UNVERIFIED')
            ->assertJsonPath('results.0.reported_access', 'Membership Required')
            ->assertJsonPath('results.0.reported_membership_required', true)
            ->assertJsonPath('results.0.reported_access_evidence', 'OPEN_CHARGE_MAP_CROWD_ACCESS_UNVERIFIED')
            ->assertJsonPath('results.0.remote_control', false)
            ->assertJsonPath('results.0.reservable', false)
            ->assertJsonPath('results.0.billing_eligible', false)
            ->assertJsonPath('results.0.connections.0.reported_power_kw', 22);
        $this->assertStringNotContainsString('synthetic-private-ocm-key', $response->getContent());
        $this->assertStringNotContainsString('DO_NOT_LEAK', $response->getContent());
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.openchargemap.io/v3/poi/')
                && $request->hasHeader('X-API-Key', 'synthetic-private-ocm-key')
                && $request['opendata'] === 'true'
                && $request['distanceunit'] === 'km'
                && $request['maxresults'] === 25
                && ! $request->hasHeader('Cookie')
                && ! str_contains($request->url(), 'synthetic-private-ocm-key');
        });
        // Same approved query reuses a short-lived source-only response.
        $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input())->assertOk();
        Http::assertSentCount(1);
    }

    public function test_unlicensed_unpublished_and_malformed_source_records_are_not_displayed(): void
    {
        $unlicensed = $this->point();
        $unlicensed['DataProvider']['IsOpenDataLicensed'] = false;
        $unpublished = $this->point();
        $unpublished['ID'] = 999;
        $unpublished['SubmissionStatusTypeID'] = 100;
        $invalid = $this->point();
        $invalid['ID'] = 1000;
        $invalid['AddressInfo']['Longitude'] = 181;
        Http::fake(fn () => Http::response([$unlicensed, $unpublished, $invalid], 200, ['Content-Type' => 'application/json']));
        $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input())
            ->assertOk()->assertJsonPath('count', 0)->assertJsonCount(0, 'results');
    }

    public function test_optional_iso_country_filter_is_bounded_and_verified_after_provider_response(): void
    {
        $us = $this->point();
        $us['ID'] = 12346;
        $us['AddressInfo']['Country']['ISOCode'] = 'US';
        Http::fake(fn () => Http::response([$us, $this->point()], 200, ['Content-Type' => 'application/json']));
        $response = $this->postJson('/api/discovery/open-charge-map/search', $this->input() + ['country' => 'AE']);
        $response->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.id', 'ocm:12345');
        Http::assertSent(fn ($request) => $request['countrycode'] === 'AE');
        // Different country constraint must use its own safe cached response.
        $response = $this->postJson('/api/discovery/open-charge-map/search', $this->input() + ['country' => 'US']);
        $response->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.id', 'ocm:12346');
        Http::assertSentCount(2);
        foreach (['ae', 'A', 'UAE', 'US1'] as $bad) {
            $this->postJson('/api/discovery/open-charge-map/search', $this->input() + ['country' => $bad])
                ->assertUnprocessable();
        }
        Http::assertSentCount(2);
    }

    public function test_unapproved_terms_and_missing_key_fail_closed_without_any_outbound_request(): void
    {
        config(['discovery.ocm_terms_accepted' => false]);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())
            ->assertStatus(503)->assertJsonPath('reason', 'not_configured');
        config(['discovery.ocm_terms_accepted' => true, 'discovery.ocm_api_key' => null]);
        $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input())
            ->assertStatus(503)->assertJsonPath('reason', 'not_configured');
        Http::assertNothingSent();
    }

    public function test_redirect_mime_oversized_and_rate_limited_upstream_fail_closed(): void
    {
        Http::fakeSequence()
            ->push('', 302, ['Location' => 'https://127.0.0.1/private'])
            ->push('not json', 200, ['Content-Type' => 'text/html'])
            ->push(str_repeat('x', 2097153), 200, ['Content-Type' => 'application/json', 'Content-Length' => '2097153'])
            ->push('unavailable', 429, ['Content-Type' => 'application/json']);
        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/discovery/open-charge-map/search', $this->input());
            $this->assertContains($response->status(), [502, 503]);
            $this->assertStringNotContainsString('synthetic-private-ocm-key', $response->getContent());
            $this->assertStringNotContainsString('127.0.0.1/private', $response->getContent());
        }
        Http::assertSentCount(4);
    }

    public function test_rate_budget_blocks_excessive_provider_requests_without_exposing_coordinates(): void
    {
        config(['discovery.requests_per_minute' => 1]);
        Http::fake(fn () => Http::response([$this->point()], 200, ['Content-Type' => 'application/json']));
        $response = $this->postJson('/api/discovery/open-charge-map/search', $this->input());
        $response->assertOk();
        $response = $this->postJson('/api/discovery/open-charge-map/search', [
            'consent' => true, 'latitude' => 25.21, 'longitude' => 55.3, 'radius_km' => 10,
        ]);
        $response->assertTooManyRequests();
        Http::assertSentCount(1);
    }

    public function test_provider_failure_does_not_echo_errors_coordinates_or_secrets(): void
    {
        Http::fake(fn () => Http::response('synthetic-private-ocm-key leaked upstream', 503, ['Content-Type' => 'text/html']));
        $response = $this->postJson('/api/discovery/open-charge-map/search', $this->input());
        $response->assertStatus(503)->assertJsonPath('reason', 'provider_unavailable');
        $this->assertStringNotContainsString('synthetic-private-ocm-key', $response->getContent());
    }
}
