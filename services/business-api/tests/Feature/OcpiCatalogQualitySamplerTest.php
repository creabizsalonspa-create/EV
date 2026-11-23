<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CatalogQualityHistory;
use App\Services\Ocpi\CatalogQualitySampler;
use App\Services\Ocpi\RecordSynchronizer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiCatalogQualitySamplerTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $suffix): array
    {
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $provider = OcpiProvider::create(['owner_id' => $owner->id,
            'name' => 'Offline source '.$suffix, 'connection_key' => 'offline-'.$suffix,
            'version' => '2.2.1', 'authorized' => true]);
        $record = [
            'country_code' => 'AE', 'party_id' => 'QTY', 'id' => $suffix,
            'publish' => true, 'name' => 'Offline synthetic station',
            'address' => 'Synthetic address', 'city' => 'Dubai', 'country' => 'ARE',
            'coordinates' => ['latitude' => '25.1', 'longitude' => '55.1'],
            'evses' => [['uid' => 'EVSE1', 'status' => 'UNKNOWN', 'connectors' => [
                ['id' => '1', 'standard' => 'IEC_62196_T2_COMBO'],
            ]]],
            'last_updated' => now()->subMinutes(10)->toISOString(),
        ];
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$record]);

        return [$owner, $provider];
    }

    public function test_scheduled_sampler_requires_opt_in_and_turns_unsynced_location_clocks_stale(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-23T10:30:00Z'));
        config(['partner_catalog.max_age_seconds' => 3600]);
        [$owner, $provider] = $this->fixture('hourly');
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->travelTo(Carbon::parse('2026-11-23T11:45:00Z'));

        $sampler = app(CatalogQualitySampler::class);
        $this->assertSame('disabled', $sampler->run()['state']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        config(['ocpi.catalog_quality_sampling_enabled' => true]);
        $first = $sampler->run(25);
        $this->assertSame(['state' => 'sampled', 'attempted' => 1,
            'sampled' => 1, 'skipped' => 0, 'failed' => 0, 'external_requests' => 0,
            'evidence' => 'STORED_PARTNER_CATALOG_TIMESTAMPS_ONLY',
            'live_availability_verified' => false, 'physical_charging_verified' => false], $first);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 2);
        $new = DB::table('ocpi_catalog_quality_history')->where('provider_id', $provider->id)
            ->orderByDesc('observed_hour')->first();
        $this->assertSame('attention', $new->state);
        $this->assertSame(1, (int) $new->stale_count);
        $this->assertSame(0, (int) $new->within_policy_count);
        $this->assertSame(0, $sampler->run()['attempted']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 2);
        Http::assertNothingSent();
    }

    public function test_sampler_respects_bounded_batch_progress_owner_suspension_and_reassignment(): void
    {
        Http::preventStrayRequests();
        config(['ocpi.catalog_quality_sampling_enabled' => true, 'partner_catalog.max_age_seconds' => 3600]);
        $this->travelTo(Carbon::parse('2026-11-23T10:30:00Z'));
        [$ownerA, $providerA] = $this->fixture('one');
        [$ownerB, $providerB] = $this->fixture('two');
        [$ownerC, $providerC] = $this->fixture('three');
        $ownerC->forceFill(['account_status' => 'suspended'])->save();
        $this->travelTo(Carbon::parse('2026-11-23T11:31:00Z'));
        $sampler = app(CatalogQualitySampler::class);
        $result = $sampler->run(1);
        $this->assertSame(1, $result['attempted']);
        $this->assertSame(1, $result['sampled']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 4);
        $result = $sampler->run(1);
        $this->assertSame(1, $result['sampled']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 5);
        $this->assertSame(0, $sampler->run()['attempted']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 5);
        $this->assertDatabaseMissing('ocpi_catalog_quality_history', [
            'provider_id' => $providerC->id, 'observed_hour' => '2026-11-23 11:00:00.000000',
        ]);
        $providerA->update(['owner_id' => $ownerB->id]);
        $this->travelTo(Carbon::parse('2026-11-23T12:31:00Z'));
        $next = $sampler->run(2);
        $this->assertSame(2, $next['sampled']);
        $this->assertDatabaseHas('ocpi_catalog_quality_history', [
            'provider_id' => $providerA->id, 'owner_id' => $ownerA->id,
        ]);
        $this->assertDatabaseHas('ocpi_catalog_quality_history', [
            'provider_id' => $providerA->id, 'owner_id' => $ownerB->id,
        ]);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 7);
        Http::assertNothingSent();
    }

    public function test_offline_sampler_rejects_out_of_range_limit_without_creating_samples(): void
    {
        config(['ocpi.catalog_quality_sampling_enabled' => true]);
        $this->expectException(\InvalidArgumentException::class);
        app(CatalogQualitySampler::class)->run(51);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 0);
    }

    public function test_idle_partner_quality_data_is_physically_purged_after_retention_without_network(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-10T10:30:00Z'));
        [$owner, $provider] = $this->fixture('idle-retention');
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->travelTo(Carbon::parse('2026-11-19T10:30:00Z'));

        // Cleanup is independent of feature enablement and source traffic.
        config(['ocpi.catalog_quality_sampling_enabled' => false]);
        $this->assertSame(1, Artisan::call('ocpi:expire-source-quality-snapshots', ['--limit' => 0]));
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->assertSame(0, Artisan::call('ocpi:expire-source-quality-snapshots', ['--limit' => 1]));
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 0);
        $this->assertSame(0, Artisan::call('ocpi:expire-source-quality-snapshots', ['--limit' => 1]));
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 0);
        $this->assertDatabaseHas('ocpi_providers', [
            'id' => $provider->id, 'owner_id' => $owner->id, 'authorized' => true,
        ]);
        $this->assertDatabaseCount('ocpi_records', 1);
        Http::assertNothingSent();
    }

    public function test_bounded_history_cleanup_only_deletes_expired_ids_from_idle_providers(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-10T10:30:00Z'));
        $this->fixture('expired-one');
        $this->fixture('expired-two');
        $this->travelTo(Carbon::parse('2026-11-18T10:30:00Z'));
        [, $current] = $this->fixture('current');
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 3);
        $result = app(CatalogQualityHistory::class)->purgeExpired(1);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['considered']);
        $this->assertSame(0, $result['external_requests']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 2);
        $this->assertDatabaseHas('ocpi_catalog_quality_history', ['provider_id' => $current->id]);
        $this->assertSame(1, app(CatalogQualityHistory::class)->purgeExpired(1)['deleted']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->assertSame(0, app(CatalogQualityHistory::class)->purgeExpired(1)['deleted']);
        $this->assertDatabaseCount('ocpi_records', 3);
        Http::assertNothingSent();
    }

    public function test_cli_command_uses_opt_in_and_bounded_offline_sampling_without_provider_fetch(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-23T10:30:00Z'));
        config(['partner_catalog.max_age_seconds' => 3600]);
        [, $provider] = $this->fixture('cli');
        $this->travelTo(Carbon::parse('2026-11-23T11:30:00Z'));
        $this->assertSame(1, Artisan::call('ocpi:sample-source-quality', ['--limit' => 0]));
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->assertSame(0, Artisan::call('ocpi:sample-source-quality', ['--limit' => 1]));
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        config(['ocpi.catalog_quality_sampling_enabled' => true]);
        $this->assertSame(0, Artisan::call('ocpi:sample-source-quality', ['--limit' => 1]));
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 2);
        $this->assertDatabaseHas('ocpi_catalog_quality_history', [
            'provider_id' => $provider->id, 'observed_hour' => '2026-11-23 11:00:00.000000',
        ]);
        $this->assertSame(0, Artisan::call('ocpi:sample-source-quality', ['--limit' => 1]));
        $this->assertSame(1, Artisan::call('ocpi:sample-source-quality', ['--limit' => 51]));
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 2);
        Http::assertNothingSent();
    }
}
