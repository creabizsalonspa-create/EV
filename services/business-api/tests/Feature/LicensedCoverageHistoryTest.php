<?php

namespace Tests\Feature;

use App\Services\Discovery\LicensedCoverageHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicensedCoverageHistoryTest extends TestCase
{
    private const AREAS = [
        ['label' => 'public-a', 'latitude' => 25.2, 'longitude' => 55.3, 'radius_km' => 10, 'country' => 'AE'],
        ['label' => 'public-b', 'latitude' => 25.5, 'longitude' => 55.5, 'radius_km' => 10, 'country' => 'AE'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'discovery.ocm_enabled' => true,
            'discovery.ocm_terms_accepted' => true,
            'discovery.ocm_api_key' => 'synthetic-private-history-key',
            'discovery.ocm_coverage_enabled' => true,
            'discovery.ocm_coverage_areas' => self::AREAS,
        ]);
        $this->travelTo(Carbon::parse('2026-11-26T06:15:00Z'));
    }

    private function populate(int $area): void
    {
        $input = self::AREAS[$area];
        unset($input['label']);
        $this->postJson('/api/discovery/open-charge-map/search', ['consent' => true] + $input)->assertOk();
    }

    public function test_hourly_history_survives_source_cache_expiry_without_inventing_coverage(): void
    {
        $history = app(LicensedCoverageHistory::class);
        $this->assertSame('no_observations', $history->capture()['state']);
        $this->assertSame(0, $history->report()['observed_hours']);
        Http::assertNothingSent();

        Http::fake(['api.openchargemap.io/*' => Http::response([], 200, ['Content-Type' => 'application/json'])]);
        $this->populate(0);
        $this->assertSame('captured', $history->capture()['state']);
        $this->populate(1);
        $this->assertSame('already_captured', $history->capture()['state']);

        $this->travel(6)->minutes();
        $report = $history->report();
        $this->assertSame(1, $report['observed_hours']);
        $this->assertSame(1, $report['samples'][0]['areas_empty']);
        $this->assertSame(1, $report['samples'][0]['unobserved_areas']);
        $this->assertSame(0, $report['samples'][0]['returned_record_observations']);
        $this->assertFalse($report['complete_geographic_coverage']);
        $this->assertFalse($report['live_status_verified']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['55.3', '25.2', 'synthetic-private-history-key', 'public-a', 'public-b'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->artisan('discovery:licensed-coverage-history')->expectsOutput($encoded)->assertSuccessful();
        Http::assertSentCount(2);

        $this->travel(1)->hours();
        $this->populate(0);
        $this->artisan('discovery:licensed-coverage-history --capture')->assertSuccessful();
        $this->assertSame(2, $history->report()['observed_hours']);
        $this->travel(168)->hours();
        $this->assertSame([], $history->report()['samples']);
        Http::assertSentCount(3);
    }

    public function test_history_counts_validated_source_quality_and_not_private_records(): void
    {
        $point = [
            'ID' => 12345,
            'DataProvider' => ['Title' => 'Synthetic contributor', 'License' => 'CC BY-SA 4.0', 'IsOpenDataLicensed' => true],
            'SubmissionStatusTypeID' => 200,
            'AddressInfo' => [
                'Title' => 'Synthetic station', 'Latitude' => 25.2, 'Longitude' => 55.3,
                'Country' => ['ISOCode' => 'AE'],
            ],
            'Connections' => [['ConnectionType' => ['Title' => 'Type 2'], 'PowerKW' => 22]],
            'DateLastStatusUpdate' => '2026-11-24T06:15:00Z',
        ];
        $unknown = array_replace($point, ['ID' => 12346, 'DateLastStatusUpdate' => 'invalid']);
        $unlicensed = array_replace($point, ['ID' => 12347, 'DataProvider' => ['IsOpenDataLicensed' => false]]);
        Http::fake(['api.openchargemap.io/*' => Http::response([$point, $unknown, $unlicensed], 200, ['Content-Type' => 'application/json'])]);
        $this->populate(0);
        $history = app(LicensedCoverageHistory::class);
        $this->assertSame('captured', $history->capture()['state']);
        $sample = $history->report()['samples'][0];
        $this->assertSame(1, $sample['areas_with_reports']);
        $this->assertSame(2, $sample['returned_record_observations']);
        $this->assertSame(1, $sample['older_than_24h_observations']);
        $this->assertSame(1, $sample['unknown_time_observations']);
        $this->assertSame(2, $sample['unknown_operating_observations']);
        $encoded = json_encode($sample, JSON_THROW_ON_ERROR);
        foreach (['12345', '12346', '12347', 'Synthetic station', 'Synthetic contributor'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        Http::assertSentCount(1);
    }

    public function test_history_is_scoped_to_current_approval_and_benchmark_configuration(): void
    {
        Http::fake(['api.openchargemap.io/*' => Http::response([], 200, ['Content-Type' => 'application/json'])]);
        $this->populate(0);
        $history = app(LicensedCoverageHistory::class);
        $this->assertSame('captured', $history->capture()['state']);

        config(['discovery.ocm_coverage_areas' => [self::AREAS[0]]]);
        $this->assertSame(0, $history->report()['observed_hours']);
        config(['discovery.ocm_coverage_areas' => self::AREAS, 'discovery.ocm_api_key' => 'synthetic-rotated-history-key']);
        $this->assertSame(0, $history->report()['observed_hours']);
        config(['discovery.ocm_terms_accepted' => false]);
        $this->assertFalse($history->report()['enabled']);
        $this->assertSame('disabled', $history->capture()['state']);
        $this->artisan('discovery:licensed-coverage-history --capture')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_corrupt_history_does_not_become_measured_coverage(): void
    {
        $scope = hash('sha256', json_encode([
            self::AREAS, hash('sha256', 'synthetic-private-history-key'),
        ], JSON_THROW_ON_ERROR));
        Cache::put('ocm-coverage-history:v1:'.$scope.':2026112606', [
            'configured_areas' => 2, 'observed_areas' => 100,
            'private' => 'DO_NOT_EXPOSE',
        ], 3600);

        $report = app(LicensedCoverageHistory::class)->report();
        $this->assertSame(0, $report['observed_hours']);
        $this->assertSame(1, $report['unverified_hours']);
        $this->assertSame([], $report['samples']);
        $this->assertStringNotContainsString('DO_NOT_EXPOSE', json_encode($report, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }
}
