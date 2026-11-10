<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
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
            'UsageCost' => 'See operator app <script>alert(1)</script>; not a quote',
            'OperatorInfo' => ['Title' => 'Synthetic Operator'],
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
            ->assertJsonPath('results.0.reported_cost_note', 'See operator app <script>alert(1)</script>; not a quote')
            ->assertJsonPath('results.0.reported_cost_evidence', 'OPEN_CHARGE_MAP_CROWD_PRICE_UNVERIFIED')
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
        $unnamed = $this->point();
        $unnamed['ID'] = 1001;
        $unnamed['DataProvider']['Title'] = '';
        $longLicense = $this->point();
        $longLicense['ID'] = 1002;
        $longLicense['DataProvider']['License'] = str_repeat('©', 241);
        $unpublished = $this->point();
        $unpublished['ID'] = 999;
        $unpublished['SubmissionStatusTypeID'] = 100;
        $invalid = $this->point();
        $invalid['ID'] = 1000;
        $invalid['AddressInfo']['Longitude'] = 181;
        Http::fake(fn () => Http::response([$unlicensed, $unpublished, $invalid, $unnamed, $longLicense], 200, ['Content-Type' => 'application/json']));
        $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input())
            ->assertOk()->assertJsonPath('count', 0)->assertJsonCount(0, 'results');
    }

    public function test_cached_source_age_is_recomputed_and_optional_time_filter_excludes_stale_reports(): void
    {
        $originalClock = now()->startOfMinute();
        Carbon::setTestNow($originalClock);
        try {
            $poi = $this->point();
            $poi['DateLastStatusUpdate'] = $originalClock->copy()->subMinutes(59)->format('Y-m-d\TH:i:s\Z');
            Http::fake(fn () => Http::response([$poi], 200, ['Content-Type' => 'application/json']));
            $this->postJson('/api/discovery/open-charge-map/search',
                $this->input() + ['max_source_age_hours' => 1])
                ->assertOk()->assertJsonPath('count', 1)
                ->assertJsonPath('results.0.source_age_minutes', 59);
            Carbon::setTestNow($originalClock->copy()->addMinutes(3));
            $this->postJson('/api/discovery/open-charge-map/search',
                $this->input() + ['max_source_age_hours' => 1])
                ->assertOk()->assertJsonPath('count', 0);
            $this->postJson('/api/discovery/open-charge-map/search', $this->input())
                ->assertOk()->assertJsonPath('count', 1)
                ->assertJsonPath('results.0.source_age_minutes', 62);
            Http::assertSentCount(1);
            foreach ([0, 169, 'invalid'] as $age) {
                $this->postJson('/api/discovery/open-charge-map/search',
                    $this->input() + ['max_source_age_hours' => $age])
                    ->assertUnprocessable();
            }
            Http::assertSentCount(1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_report_freshness_uses_exact_seconds_not_rounded_display_minutes(): void
    {
        $clock = now()->startOfMinute();
        Carbon::setTestNow($clock);
        try {
            $exact = $this->point();
            $exact['DateLastStatusUpdate'] = $clock->copy()->subHour()->format('Y-m-d\TH:i:s\Z');
            $older = $this->point();
            $older['ID'] = 23456;
            $older['DateLastStatusUpdate'] = $clock->copy()->subHour()->subSeconds(5)->format('Y-m-d\TH:i:s\Z');
            Http::fake(fn () => Http::response([$older, $exact], 200, ['Content-Type' => 'application/json']));
            $this->postJson('/api/discovery/open-charge-map/search',
                $this->input() + ['max_source_age_hours' => 1])
                ->assertOk()->assertJsonPath('count', 1)
                ->assertJsonPath('results.0.id', 'ocm:12345')
                ->assertJsonPath('results.0.source_age_minutes', 60);
            $this->postJson('/api/discovery/open-charge-map/search', $this->input())
                ->assertOk()->assertJsonPath('count', 2)
                ->assertJsonPath('results.1.source_age_minutes', 60);
            Http::assertSentCount(1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_source_reported_operator_access_and_operational_filters_share_one_upstream_lookup(): void
    {
        $other = $this->point();
        $other['ID'] = 23456;
        $other['OperatorInfo']['Title'] = 'Other Network';
        $other['UsageType']['IsMembershipRequired'] = false;
        $other['StatusType']['IsOperational'] = false;
        Http::fake(fn () => Http::response([$this->point(), $other], 200, ['Content-Type' => 'application/json']));
        $this->postJson('/api/discovery/open-charge-map/search',
            $this->input() + [
                'operator_contains' => 'synthetic',
                'reported_membership_required' => true,
                'reported_operational' => true,
            ])
            ->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.id', 'ocm:12345')
            ->assertJsonPath('results.0.availability', 'unknown');
        $this->postJson('/api/discovery/open-charge-map/search',
            $this->input() + [
                'operator_contains' => 'OTHER',
                'reported_membership_required' => false,
                'reported_operational' => false,
            ])
            ->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.id', 'ocm:23456')
            ->assertJsonPath('results.0.remote_control', false);
        Http::assertSentCount(1);
        foreach ([
            ['operator_contains' => str_repeat('X', 121)],
            ['reported_operational' => 'maybe'],
            ['reported_membership_required' => 'maybe'],
        ] as $invalid) {
            $this->postJson('/api/discovery/open-charge-map/search', $this->input() + $invalid)
                ->assertUnprocessable();
        }
        Http::assertSentCount(1);
    }

    public function test_optional_connection_filters_are_bounded_and_match_the_same_unverified_connector(): void
    {
        $both = $this->point();
        $different = $this->point();
        $different['ID'] = 12400;
        $different['Connections'] = [
            ['ConnectionType' => ['Title' => 'Type 2'], 'PowerKW' => 7],
            ['ConnectionType' => ['Title' => 'CCS'], 'PowerKW' => 150],
        ];
        Http::fake(fn () => Http::response([$both, $different], 200, ['Content-Type' => 'application/json']));
        $filters = $this->input() + ['reported_connector' => 'type 2', 'min_reported_power_kw' => 20];
        $response = $this->postJson('/api/discovery/open-charge-map/search', $filters);
        $response->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.id', 'ocm:12345')
            ->assertJsonPath('results.0.availability', 'unknown')
            ->assertJsonPath('results.0.billing_eligible', false);
        // Different bound query must not receive previous cached matches.
        $this->postJson('/api/discovery/open-charge-map/search',
            $this->input() + ['reported_connector' => 'CCS', 'min_reported_power_kw' => 100])
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.id', 'ocm:12400');
        // Source query is identical: switch local type and power filters
        // without a second billed/rate-limited call to the provider.
        Http::assertSentCount(1);
        foreach ([
            ['min_reported_power_kw' => 0],
            ['min_reported_power_kw' => 1001],
            ['reported_connector' => str_repeat('X', 81)],
        ] as $bad) {
            $this->postJson('/api/discovery/open-charge-map/search', $this->input() + $bad)
                ->assertUnprocessable();
        }
        Http::assertSentCount(1);
    }

    public function test_unicode_operator_and_connector_filters_use_literal_casefolding_and_codepoint_lengths(): void
    {
        $poi = $this->point();
        $poi['OperatorInfo']['Title'] = 'Énergie Express';
        $poi['Connections'][0]['ConnectionType']['Title'] = 'Type É';
        Http::fake(fn () => Http::response([$poi], 200, ['Content-Type' => 'application/json']));
        $this->postJson('/api/discovery/open-charge-map/search', $this->input() + [
            'operator_contains' => 'éNERGIE',
            'reported_connector' => 'type é',
            'min_reported_power_kw' => 20,
        ])->assertOk()->assertJsonPath('count', 1)->assertJsonPath('results.0.id', 'ocm:12345');
        // User text remains literal, never a supplied regular expression.
        $this->postJson('/api/discovery/open-charge-map/search', $this->input() + [
            'operator_contains' => '.*',
        ])->assertOk()->assertJsonPath('count', 0);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input() + [
            'operator_contains' => str_repeat('é', 100),
        ])->assertOk()->assertJsonPath('count', 0);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input() + [
            'operator_contains' => str_repeat('é', 121),
        ])->assertUnprocessable();
        Http::assertSentCount(1);
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
