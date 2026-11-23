<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\RecordSynchronizer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Stored source quality never grants roaming, physical status or payment. */
class OcpiCatalogQualityHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['partner_catalog.max_age_seconds' => 3600]);
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'connection_key' => 'synthetic-quality-only',
            'name' => 'Private hourly quality test', 'authorized' => true, 'version' => '2.2.1',
        ]);

        return [$owner, $provider, '/api/ocpi/providers/'.$provider->id.'/catalog-quality-history'];
    }

    private function location(string $id, bool $stale = false): array
    {
        return [
            'country_code' => 'AE', 'party_id' => 'QTY', 'id' => $id,
            'publish' => true, 'name' => 'Synthetic source only',
            'address' => 'Offline source road', 'city' => 'Dubai', 'country' => 'ARE',
            'coordinates' => ['latitude' => '25.1', 'longitude' => '55.1'],
            'evses' => [['uid' => 'E1', 'status' => 'UNKNOWN', 'connectors' => [
                ['id' => '1', 'standard' => 'IEC_62196_T2_COMBO'],
            ]]],
            'last_updated' => $stale ? now()->subHours(3)->toISOString() : now()->subMinutes(10)->toISOString(),
        ];
    }

    public function test_quality_history_records_real_changed_ingest_and_not_duplicate_or_conflicting_batches(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23T10:30:00Z'));
        [$owner, $provider, $url] = $this->fixture();
        $service = app(RecordSynchronizer::class);
        $first = $this->location('recent');
        $service->ingest($provider, 'locations', [$first]);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->assertSame(1, $service->ingest($provider, 'locations', [$first])['duplicate']);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);

        $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('scope', 'stored_partner_catalog_quality_history')
            ->assertJsonPath('sample_completeness', 'STORED_SYNC_AND_OPTIONAL_PERIODIC_SNAPSHOTS')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('samples.0.published_count', 1)
            ->assertJsonPath('samples.0.within_policy_count', 1)
            ->assertJsonPath('samples.0.stale_count', 0)
            ->assertJsonPath('samples.0.observed_hour', '2026-11-23T10:00:00.000000Z')
            ->assertJsonPath('source_covers_whole_network', false)
            ->assertJsonPath('live_availability_verified', false)
            ->assertJsonPath('remote_control', false)
            ->assertJsonMissingPath('samples.0.provider_location');

        $this->travelTo(Carbon::parse('2026-11-23T10:40:00Z'));
        $service->ingest($provider, 'locations', [$this->location('old-report', true)]);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('samples.0.published_count', 2)
            ->assertJsonPath('samples.0.within_policy_count', 1)
            ->assertJsonPath('samples.0.stale_count', 1);
        $this->travelTo(Carbon::parse('2026-11-23T11:01:00Z'));
        $service->ingest($provider, 'locations', [$this->location('next-hour')]);
        $this->getJson($url)->assertOk()->assertJsonPath('count', 2)
            ->assertJsonPath('samples.0.published_count', 3)
            ->assertJsonPath('samples.0.observed_hour', '2026-11-23T11:00:00.000000Z')
            ->assertJsonPath('samples.1.published_count', 2);

        $changed = $this->location('recent');
        $changed['last_updated'] = $first['last_updated'];
        $changed['name'] = 'Conflicting replay';
        try {
            $service->ingest($provider, 'locations', [$this->location('never-inserted'), $changed]);
            $this->fail('A conflict must roll back catalog and quality samples.');
        } catch (OcpiException) {
            // Source replay conflicts remain fail-closed and atomic.
        }
        $this->assertDatabaseCount('ocpi_records', 3);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 2);
        $this->assertDatabaseMissing('ocpi_records', ['external_id' => 'never-inserted']);
        Http::assertNothingSent();
    }

    public function test_history_is_tenant_scoped_and_stale_identity_cannot_read_quality_evidence(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$this->location('owned')]);
        $other = User::factory()->create(['role' => 'vendor']);
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson($url)->assertForbidden();
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertOk()
            ->assertJsonPath('count', 1);
        DB::table('users')->where('id', $owner->id)->increment('auth_epoch');
        $this->actingAs($owner)->getJson($url)->assertConflict();
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        Http::assertNothingSent();
    }

    public function test_invalid_stored_quality_counts_refuse_partial_trend_and_no_fetch_occurs(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$this->location('one')]);
        DB::table('ocpi_catalog_quality_history')->where('provider_id', $provider->id)
            ->update(['within_policy_count' => 900]);
        $this->actingAs($owner)->getJson($url)->assertConflict()->assertJsonMissingPath('samples');
        DB::table('ocpi_catalog_quality_history')->where('provider_id', $provider->id)
            ->update(['within_policy_count' => 1, 'state' => 'attention']);
        $this->getJson($url)->assertConflict()->assertJsonMissingPath('samples');
        Http::assertNothingSent();
    }

    public function test_quality_history_expires_after_seven_days_even_if_provider_never_syncs_again(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10T10:30:00Z'));
        [$owner, $provider, $url] = $this->fixture();
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$this->location('week-old')]);
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('count', 1);
        $this->travelTo(Carbon::parse('2026-11-19T10:30:00Z'));
        $this->getJson($url)->assertOk()->assertJsonPath('count', 0)->assertJsonPath('samples', []);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$this->location('recent')]);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('samples.0.observed_hour', '2026-11-19T10:00:00.000000Z');
        Http::assertNothingSent();
    }

    public function test_invalid_policy_records_unknown_state_without_issuing_fake_freshness_counts(): void
    {
        [$owner, $provider, $url] = $this->fixture();
        config(['partner_catalog.max_age_seconds' => null]);
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$this->location('one')]);
        $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertJsonPath('samples.0.state', 'policy_unavailable')
            ->assertJsonPath('samples.0.published_count', null)
            ->assertJsonPath('samples.0.within_policy_count', null)
            ->assertJsonPath('samples.0.max_age_seconds', null)
            ->assertJsonPath('live_availability_verified', false);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        Http::assertNothingSent();
    }

    public function test_partner_reassignment_never_exposes_previous_owners_quality_history(): void
    {
        [$formerOwner, $provider, $url] = $this->fixture();
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$this->location('former-owner')]);
        $this->actingAs($formerOwner)->getJson($url)->assertOk()->assertJsonPath('count', 1);

        $newOwner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $provider->update(['owner_id' => $newOwner->id]);
        $this->actingAs($formerOwner)->getJson($url)->assertNotFound();
        $this->actingAs($newOwner)->getJson($url)->assertOk()
            ->assertJsonPath('count', 0)->assertJsonPath('samples', []);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertOk()
            ->assertJsonPath('count', 0);
        $this->assertDatabaseHas('ocpi_catalog_quality_history', [
            'provider_id' => $provider->id, 'owner_id' => $formerOwner->id,
        ]);
        $this->assertDatabaseCount('ocpi_catalog_quality_history', 1);
        Http::assertNothingSent();
    }
}
