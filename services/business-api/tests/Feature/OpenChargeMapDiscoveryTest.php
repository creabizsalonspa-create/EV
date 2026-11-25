<?php

namespace Tests\Feature;

use App\Services\Discovery\LicensedCoverageAudit;
use App\Services\Discovery\LicensedSourceCache;
use App\Services\Discovery\LicensedSourceQuality;
use App\Services\Discovery\LookupFailure;
use App\Services\Discovery\OpenChargeMapAdapter;
use App\Services\Discovery\OpenChargeMapProjection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_optional_operator_quality_counts_cold_fetches_only_without_request_metadata(): void
    {
        config(['discovery.ocm_quality_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-24T06:15:00Z'));
        $fresh = $this->point();
        $fresh['DateLastStatusUpdate'] = now()->subMinute()->utc()->format('Y-m-d\\TH:i:s\\Z');
        $old = $this->point();
        $old['ID'] = 12346;
        $old['DateLastStatusUpdate'] = now()->subDays(2)->utc()->format('Y-m-d\\TH:i:s\\Z');
        $unknown = $this->point();
        $unknown['ID'] = 12347;
        unset($unknown['StatusType']);
        Http::fake(['api.openchargemap.io/*' => Http::response([$fresh, $old, $unknown], 200, ['Content-Type' => 'application/json'])]);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input() + ['max_source_age_hours' => 1])->assertOk()->assertJsonPath('count', 1);
        $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input())->assertOk()->assertJsonPath('count', 3);
        $report = app(LicensedSourceQuality::class)->report();
        $this->assertSame(1, $report['observed_hours']);
        $sample = $report['samples'][0];
        $this->assertSame(1, $sample['successful_fetches']);
        $this->assertSame(3, $sample['returned_record_observations']);
        $this->assertSame(1, $sample['unknown_time_observations']);
        $this->assertSame(1, $sample['older_than_24h_observations']);
        $this->assertSame(1, $sample['unknown_operating_observations']);
        $this->assertFalse($report['complete_geographic_coverage']);
        $this->assertFalse($report['live_status_verified']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['25.2', '55.3', 'synthetic-private-ocm-key', '12345', 'Fixture street'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->artisan('discovery:licensed-quality')->expectsOutput($encoded)->assertSuccessful();
        Http::assertSentCount(1);
        $this->travel(24)->hours();
        $this->assertSame([], app(LicensedSourceQuality::class)->report()['samples']);
    }

    public function test_quality_monitor_distinguishes_upstream_busy_from_local_cooldown_and_is_opt_in(): void
    {
        $quality = app(LicensedSourceQuality::class);
        config(['discovery.ocm_quality_enabled' => true]);
        Http::fake(['api.openchargemap.io/*' => Http::response('', 429, ['Retry-After' => '60'])]);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertStatus(503)->assertJsonPath('reason', 'provider_busy');
        $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input())->assertStatus(503)->assertJsonPath('reason', 'provider_busy');
        Http::assertSentCount(1);
        $this->assertSame(1, $quality->report()['samples'][0]['busy_responses']);
        $this->assertSame(0, $quality->report()['samples'][0]['successful_fetches']);
        config(['discovery.ocm_quality_enabled' => false]);
        $this->assertFalse($quality->report()['enabled']);
        $this->assertSame([], $quality->report()['samples']);
    }

    public function test_offline_benchmark_coverage_distinguishes_missing_empty_and_observed_samples(): void
    {
        $areas = [
            ['label' => 'public-sample-a', 'latitude' => 25.2, 'longitude' => 55.3, 'radius_km' => 10, 'country' => 'AE'],
            ['label' => 'public-sample-b', 'latitude' => 25.5, 'longitude' => 55.5, 'radius_km' => 10, 'country' => 'AE'],
            ['label' => 'public-sample-c', 'latitude' => 26.0, 'longitude' => 56.0, 'radius_km' => 10, 'country' => 'AE'],
        ];
        config(['discovery.ocm_coverage_enabled' => true, 'discovery.ocm_coverage_areas' => $areas]);
        $audit = app(LicensedCoverageAudit::class);
        $report = $audit->report();
        $this->assertSame(0, $report['observed_areas']);
        Http::assertNothingSent();
        Http::fake(fn () => Http::response([$this->point()], 200, ['Content-Type' => 'application/json']));
        foreach (array_slice($areas, 0, 2) as $area) {
            unset($area['label']);
            $this->postJson('/api/discovery/open-charge-map/search', ['consent' => true] + $area)->assertOk();
        }
        $report = $audit->report();
        $this->assertSame(3, $report['configured_areas']);
        $this->assertSame(2, $report['observed_areas']);
        $this->assertSame(1, $report['areas_with_reports']);
        $this->assertSame(['observed_reports', 'observed_empty', 'unobserved'], array_column($report['areas'], 'state'));
        $this->assertSame(1, $report['areas'][0]['quality_sample']['returned_count']);
        $this->assertSame(0, $report['areas'][1]['quality_sample']['returned_count']);
        $this->assertNull($report['areas'][2]['quality_sample']);
        $this->assertFalse($report['complete_geographic_coverage']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('55.3', $encoded);
        $this->assertStringNotContainsString('12345', $encoded);
        $this->assertStringNotContainsString('synthetic-private-ocm-key', $encoded);
        $this->artisan('discovery:licensed-coverage')->expectsOutput($encoded)->assertSuccessful();
        Http::assertSentCount(2);
        $this->travel(6)->minutes();
        $this->assertSame(0, $audit->report()['observed_areas']);
        config(['discovery.ocm_coverage_areas' => [$areas[0], array_replace($areas[0], ['label' => 'duplicate-area'])]]);
        $this->assertSame('invalid_benchmarks', $audit->report()['reason']);
        $this->artisan('discovery:licensed-coverage')->assertFailed();
        Http::assertSentCount(2);
    }

    public function test_cached_source_must_still_match_the_original_geographic_query(): void
    {
        $area = ['label' => 'public-sample', 'latitude' => 25.2, 'longitude' => 55.3, 'radius_km' => 10, 'country' => 'AE'];
        config(['discovery.ocm_coverage_enabled' => true, 'discovery.ocm_coverage_areas' => [$area]]);
        $input = $this->input() + ['country' => 'AE'];
        Http::fake(fn () => Http::response([$this->point()], 200, ['Content-Type' => 'application/json']));
        $this->postJson('/api/discovery/open-charge-map/search', $input)->assertOk();
        $key = 'ocm-discovery:'.LicensedSourceCache::identity(25.2, 55.3, 10, 'AE', 'synthetic-private-ocm-key');
        $cached = Cache::get($key);
        foreach ([
            ['longitude' => 56.0],
            ['country' => 'US'],
            ['distance_km' => 1.0],
        ] as $difference) {
            $corrupt = $cached;
            $corrupt['results'][0] = array_replace($corrupt['results'][0], $difference);
            Cache::put($key, $corrupt, 300);
            $this->postJson('/api/v1/discovery/open-charge-map/search', $input)
                ->assertStatus(503)->assertJsonPath('reason', 'invalid_response')
                ->assertJsonMissingPath('results');
        }
        $report = app(LicensedCoverageAudit::class)->report();
        $this->assertSame(0, $report['observed_areas']);
        $this->assertSame('unverified_cache', $report['areas'][0]['state']);
        Http::assertSentCount(1);
    }

    public function test_non_array_cached_source_returns_private_unavailable_without_provider_io(): void
    {
        $input = $this->input() + ['country' => 'AE'];
        $key = 'ocm-discovery:'.LicensedSourceCache::identity(25.2, 55.3, 10, 'AE', 'synthetic-private-ocm-key');
        Cache::put($key, 'synthetic-corrupt-cache', 300);

        $this->postJson('/api/v1/discovery/open-charge-map/search', $input)
            ->assertStatus(503)->assertJsonPath('reason', 'invalid_response')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('results');
        Http::assertNothingSent();
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

    public function test_native_contract_matches_the_shared_flutter_license_and_quality_fixture(): void
    {
        Http::fake(['api.openchargemap.io/*' => Http::response([$this->point()], 200, ['Content-Type' => 'application/json'])]);
        $expected = json_decode(file_get_contents(base_path('../../tests/Fixtures/native-licensed-discovery.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input())
            ->assertOk()->assertExactJson($expected);
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

    public function test_provider_backoff_covers_other_coordinates_and_both_api_mounts_then_expires(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20T01:00:00Z'));
        Http::fakeSequence()
            ->push('', 429, ['Retry-After' => '120'])
            ->push([$this->point()], 200, ['Content-Type' => 'application/json']);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())
            ->assertStatus(503)->assertHeader('Retry-After', '120');
        $this->travel(30)->seconds();
        $this->postJson('/api/v1/discovery/open-charge-map/search', array_replace($this->input(), ['radius_km' => 11]))
            ->assertStatus(503)->assertHeader('Retry-After', '90');
        Http::assertSentCount(1);
        $this->travel(91)->seconds();
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertOk();
        Http::assertSentCount(2);
    }

    public function test_provider_backoff_preserves_valid_cached_results_and_rotated_credential_is_independent(): void
    {
        Http::fakeSequence()
            ->push([$this->point()], 200, ['Content-Type' => 'application/json'])
            ->push('', 429, ['Retry-After' => '300'])
            ->push([$this->point()], 200, ['Content-Type' => 'application/json']);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertOk();
        $this->postJson('/api/discovery/open-charge-map/search', array_replace($this->input(), ['radius_km' => 11]))->assertStatus(503);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertOk();
        Http::assertSentCount(2);
        config(['discovery.ocm_api_key' => 'rotated-synthetic-private-key']);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertOk();
        Http::assertSentCount(3);
    }

    public function test_single_flight_refuses_parallel_cold_query_without_provider_io_and_releases_after_failure(): void
    {
        $lock = Cache::lock('ocm-flight:'.hash('sha256', 'synthetic-private-ocm-key'), 30);
        $this->assertTrue($lock->get());
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())
            ->assertStatus(503)->assertHeader('Retry-After', '1');
        Http::assertNothingSent();
        $lock->release();
        Http::fakeSequence()->push('', 503)->push([$this->point()], 200, ['Content-Type' => 'application/json']);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertStatus(503);
        $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertOk();
        Http::assertSentCount(2);
    }

    public function test_different_queries_cannot_overlap_provider_egress_for_one_credential(): void
    {
        $cache = new LicensedSourceCache;
        $calls = 0;
        $source = $cache->read('outer-query', 'private-credential', function () use ($cache, &$calls) {
            $calls++;
            try {
                $cache->read('different-query', 'private-credential', function () use (&$calls) {
                    $calls++;

                    return [];
                });
                $this->fail('Overlapping provider lookup must be rejected.');
            } catch (LookupFailure $error) {
                $this->assertSame('provider_busy', $error->reason);
                $this->assertSame(1, $error->retryAfter);
            }
            // Another authorized credential does not share this egress lease.
            $this->assertSame([], $cache->read('independent-query', 'different-credential', fn () => []));

            return ['fixture' => true];
        });
        $this->assertSame(['fixture' => true], $source);
        $this->assertSame(1, $calls);
        $this->assertSame([], $cache->read('different-query', 'private-credential', fn () => []));
    }

    public function test_http_date_and_malformed_retry_headers_are_bounded_without_exposing_upstream_text(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20T01:00:00Z'));
        Http::fakeSequence()
            ->push('', 429, ['Retry-After' => 'Fri, 20 Nov 2026 01:02:00 GMT'])
            ->push('', 429, ['Retry-After' => 'private-provider-detail'])
            ->push('', 429, ['Retry-After' => '9999999999']);
        foreach (['120', '60', '86400'] as $expected) {
            Cache::flush();
            $response = $this->postJson('/api/discovery/open-charge-map/search', $this->input())
                ->assertStatus(503)->assertHeader('Retry-After', $expected);
            $this->assertStringNotContainsString('private-provider-detail', $response->getContent());
        }
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

    public function test_capped_crowd_sample_quality_is_recomputed_after_source_filters_without_suggesting_coverage(): void
    {
        $clock = Carbon::parse('2026-11-16T00:00:00Z');
        Carbon::setTestNow($clock);
        try {
            $old = $this->point();
            $old['DateLastStatusUpdate'] = $clock->copy()->subHours(25)->toIso8601ZuluString();

            $missingTime = $this->point();
            $missingTime['ID'] = 12346;
            unset($missingTime['StatusType']);

            $future = $this->point();
            $future['ID'] = 12347;
            $future['DateLastStatusUpdate'] = $clock->copy()->addMinutes(5)->toIso8601ZuluString();
            unset($future['StatusType']);

            $recent = $this->point();
            $recent['ID'] = 12348;
            $recent['DateLastStatusUpdate'] = $clock->copy()->subHour()->toIso8601ZuluString();
            $recent['StatusType'] = ['IsOperational' => false];

            Http::fake(fn () => Http::response([$old, $missingTime, $future, $recent], 200, ['Content-Type' => 'application/json']));

            $reply = $this->postJson('/api/discovery/open-charge-map/search', $this->input())->assertOk()
                ->assertJsonPath('count', 4)
                ->assertJsonPath('quality_sample.evidence', 'FILTERED_LICENSED_CROWD_SAMPLE_ONLY')
                ->assertJsonPath('quality_sample.returned_count', 4)
                ->assertJsonPath('quality_sample.source_time_unknown_or_future_count', 2)
                ->assertJsonPath('quality_sample.source_older_than_24h_count', 1)
                ->assertJsonPath('quality_sample.operational_report_unknown_count', 2)
                ->assertJsonPath('quality_sample.max_source_sample', 25)
                ->assertJsonPath('quality_sample.complete_geographic_coverage', false)
                ->assertJsonPath('quality_sample.live_status_verified', false);
            $this->assertStringNotContainsString('synthetic-private-ocm-key', $reply->getContent());

            // Local filter responses are freshly evaluated from one cached
            // licensed subset. Unknown/future/old clocks cannot count as fresh.
            $this->postJson('/api/v1/discovery/open-charge-map/search', $this->input() + [
                'max_source_age_hours' => 2,
            ])->assertOk()->assertJsonPath('count', 1)
                ->assertJsonPath('quality_sample.returned_count', 1)
                ->assertJsonPath('quality_sample.source_time_unknown_or_future_count', 0)
                ->assertJsonPath('quality_sample.source_older_than_24h_count', 0)
                ->assertJsonPath('quality_sample.operational_report_unknown_count', 0)
                ->assertJsonPath('quality_sample.complete_geographic_coverage', false);
            $this->postJson('/api/discovery/open-charge-map/search', $this->input() + [
                'reported_operational' => true,
            ])->assertOk()->assertJsonPath('count', 1)
                ->assertJsonPath('quality_sample.source_older_than_24h_count', 1);
            Http::assertSentCount(1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_corrupted_cached_quality_sample_cannot_duplicate_or_forge_reported_flags(): void
    {
        Http::fake(['api.openchargemap.io/*' => Http::response([$this->point()], 200, ['Content-Type' => 'application/json'])]);
        $adapter = app(OpenChargeMapAdapter::class);
        $source = $adapter->search(25.2, 55.3, 10, 'synthetic-private-ocm-key');
        $this->assertSame(1, $source['count']);

        foreach (['duplicate', 'operational', 'membership', 'access_key', 'label'] as $tamper) {
            $corrupt = $source;
            if ($tamper === 'duplicate') {
                $corrupt['results'][] = $corrupt['results'][0];
            } elseif ($tamper === 'operational') {
                $corrupt['results'][0]['reported_operational'] = 'true';
            } elseif ($tamper === 'membership') {
                $corrupt['results'][0]['reported_membership_required'] = 1;
            } elseif ($tamper === 'access_key') {
                $corrupt['results'][0]['reported_access_key_required'] = ['unexpected' => true];
            } else {
                $corrupt['results'][0]['reported_access'] = ['untrusted' => 'metadata'];
            }

            try {
                $adapter->filterReported($corrupt);
                $this->fail('Corrupted crowd report was accepted: '.$tamper);
            } catch (LookupFailure $failure) {
                $this->assertSame('invalid_response', $failure->reason);
            }
        }
        Http::assertSentCount(1);
        $this->assertSame('unknown', $source['availability']);
        $this->assertFalse($source['remote_control']);
        $this->assertFalse($source['reservable']);
    }

    public function test_impossible_upstream_calendar_date_remains_unknown_and_cannot_pass_freshness(): void
    {
        $poi = $this->point();
        $poi['DateLastStatusUpdate'] = '2026-02-30T12:00:00Z';
        Http::fake(['api.openchargemap.io/*' => Http::response([$poi], 200, ['Content-Type' => 'application/json'])]);

        $this->postJson('/api/discovery/open-charge-map/search', $this->input())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.source_updated_at', null)
            ->assertJsonPath('results.0.source_age_minutes', null)
            ->assertJsonPath('results.0.source_clock_evidence', 'SOURCE_TIME_UNKNOWN')
            ->assertJsonPath('quality_sample.source_time_unknown_or_future_count', 1)
            ->assertJsonPath('results.0.availability', 'unknown')
            ->assertJsonPath('results.0.remote_control', false)
            ->assertJsonPath('results.0.reservable', false);
        $this->postJson('/api/discovery/open-charge-map/search',
            $this->input() + ['max_source_age_hours' => 1])
            ->assertOk()
            ->assertJsonPath('count', 0);
        Http::assertSentCount(1);
    }

    public function test_crowd_report_with_impossible_utc_offset_cannot_become_fresh_source_evidence(): void
    {
        // DateTimeImmutable accepts +14:30 as a valid offset even though
        // real ISO 8601 offset limits stop at +/-14:00. Source time evidence
        // must be verified before Carbon/cache age calculations.
        $clock = new \DateTimeImmutable('2026-11-16T12:00:00Z');
        foreach ([
            '2026-11-16T10:30:00+14:30',
            '2026-11-16T10:30:00-14:01',
            '2026-11-16T10:30:00+99:99',
            '2026-11-16T10:30:00+00:61',
            '2026-02-30T10:30:00+02:00',
        ] as $invalid) {
            $poi = $this->point();
            $poi['DateLastStatusUpdate'] = $invalid;
            $readout = OpenChargeMapProjection::item($poi, $clock);
            $this->assertIsArray($readout);
            $this->assertNull($readout['source_updated_at'], 'Impossible upstream source instant cannot be trusted');
            $this->assertNull($readout['source_age_minutes']);
            $this->assertSame('SOURCE_TIME_UNKNOWN', $readout['source_clock_evidence']);
            $this->assertSame('unknown', $readout['availability']);
            $this->assertFalse($readout['remote_control']);
            $this->assertFalse($readout['reservable']);
            $this->assertFalse($readout['billing_eligible']);
        }
        $valid = $this->point();
        $valid['DateLastStatusUpdate'] = '2026-11-16T10:30:00+14:00';
        $normal = OpenChargeMapProjection::item($valid, $clock);
        $this->assertSame('2026-11-15T20:30:00Z', $normal['source_updated_at']);
        $this->assertSame('CROWD_REPORTED_TIME', $normal['source_clock_evidence']);
        Http::assertNothingSent();
    }

    public function test_invalid_cached_calendar_date_cannot_be_promoted_to_fresh_source(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15T12:00:00Z'));
        DB::enableQueryLog();
        try {
            Http::fake(['api.openchargemap.io/*' => Http::response([$this->point()], 200, ['Content-Type' => 'application/json'])]);
            $adapter = app(OpenChargeMapAdapter::class);
            $source = $adapter->search(25.2, 55.3, 10, 'synthetic-private-ocm-key');
            $this->assertSame('ocm:12345', $source['results'][0]['id']);

            // A corrupted but syntactically plausible cache record would
            // previously roll February 30 into March and appear source-dated.
            $source['results'][0]['source_updated_at'] = '2026-02-30T00:00:00Z';
            $filtered = $adapter->filterReported($source);
            $this->assertNull($filtered['results'][0]['source_age_minutes']);
            $this->assertSame('SOURCE_TIME_UNKNOWN', $filtered['results'][0]['source_clock_evidence']);
            $this->assertSame(1, $filtered['quality_sample']['source_time_unknown_or_future_count']);
            $this->assertSame('unknown', $filtered['availability']);
            $this->assertFalse($filtered['reservable']);
            $this->assertFalse($filtered['remote_control']);
            $this->assertSame(0, $adapter->filterReported($source, maxSourceAgeHours: 1)['count']);
            Http::assertSentCount(1);
            // This pure discovery adapter must not touch any SQL store,
            // including financial, charger or partner-write tables. The
            // test intentionally runs without unrelated DB migrations.
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            Carbon::setTestNow();
        }
    }
}
