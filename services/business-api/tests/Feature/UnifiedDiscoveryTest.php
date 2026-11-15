<?php

namespace Tests\Feature;

use App\Http\Controllers\UnifiedDiscoveryController;
use App\Models\Finance\TariffVersion;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Models\User;
use App\Services\Discovery\PartnerCatalogAge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UnifiedDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Shared 100%_hub', 'source' => 'simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Shared operator', 'connection_key' => 'private-unified-key', 'authorized' => true, 'version' => '2.2.1']);
        $record = OcpiRecord::create(['ocpi_provider_id' => $provider->id, 'module' => 'locations', 'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'same-local-id', 'payload' => ['private' => 'UNIFIED-PRIVATE-PAYLOAD'], 'content_hash' => str_repeat('a', 64), 'normalized' => ['name' => 'Shared partner', 'publish' => true, 'address' => '100%_hub', 'city' => 'Dubai', 'country' => 'ARE', 'connector_standards' => ['IEC_62196_T2_COMBO'], 'evses' => [], 'private_credential' => 'PRIVATE-NORMALIZED', 'remote_control' => true, 'reservable' => true], 'source_updated_at' => now()->subHour(), 'received_at' => now()]);

        return compact('station', 'provider', 'record');
    }

    public function test_catalog_age_is_consistent_without_fabricating_freshness_or_authority(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00Z'));
        $f = $this->fixtures();
        $this->putJson('/api/roaming/favorites/'.$f['record']->id)->assertOk();
        foreach ([[-1500, 1500, 'PARTNER_REPORTED_TIME'], [0, 0, 'PARTNER_REPORTED_TIME'], [1, null, 'PARTNER_CLOCK_AHEAD']] as [$offset, $age, $evidence]) {
            $f['record']->update(['source_updated_at' => now()->addMinutes($offset), 'received_at' => now()->addMinute()]);
            foreach (['/api/discovery/search?scope=partner', '/api/v1/discovery/search?scope=partner', '/api/roaming/locations', '/api/roaming/favorites'] as $path) {
                $this->getJson($path)->assertOk()->assertJsonPath('data.0.reported_source_age_minutes', $age)
                    ->assertJsonPath('data.0.source_clock_evidence', $evidence)
                    ->assertJsonPath('data.0.received_age_minutes', null)
                    ->assertJsonPath('data.0.catalog_checked_at', '2026-11-15T12:00:00.000000Z')
                    ->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false)
                    ->assertJsonPath('data.0.reservable', false)->assertDontSee('UNIFIED-PRIVATE-PAYLOAD');
            }
        }
        $unknown = PartnerCatalogAge::present(new OcpiRecord, now());
        $this->assertNull($unknown['reported_source_age_minutes']);
        $this->assertSame('SOURCE_TIMESTAMP_UNKNOWN', $unknown['source_clock_evidence']);
        $ahead = new OcpiRecord(['source_updated_at' => now()->addMicrosecond(), 'received_at' => now()->addMicrosecond()]);
        $future = PartnerCatalogAge::present($ahead, now());
        $this->assertNull($future['reported_source_age_minutes']);
        $this->assertNull($future['received_age_minutes']);
        $this->assertSame('PARTNER_CLOCK_AHEAD', $future['source_clock_evidence']);
        $f['provider']->update(['authorized' => false]);
        $this->getJson('/api/roaming/favorites')->assertJsonPath('data.0.catalog_checked_at', null)
            ->assertJsonPath('data.0.source_clock_evidence', null)->assertJsonPath('data.0.reported_source_age_minutes', null);
        $this->getJson('/api/discovery/search?scope=partner')->assertJsonPath('total', 0);
        Http::assertNothingSent();
    }

    public function test_source_age_filter_preserves_microseconds_at_both_bounds(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00.500000Z'));
        $f = $this->fixtures();
        foreach ([['2026-11-15T12:00:00.250000Z', 1], ['2026-11-15T12:00:00.500000Z', 1],
            ['2026-11-15T12:00:00.500001Z', 0], ['2026-11-14T12:00:00.500000Z', 1],
            ['2026-11-14T12:00:00.499999Z', 0]] as [$source, $count]) {
            $f['record']->update(['source_updated_at' => Carbon::parse($source)]);
            foreach (['/api/discovery/search?scope=partner&', '/api/v1/discovery/search?scope=partner&',
                '/api/roaming/locations?'] as $path) {
                $this->getJson($path.'max_source_age_hours=24')->assertOk()->assertJsonPath('total', $count);
            }
        }
        Http::assertNothingSent();
    }

    public function test_partner_report_age_filter_is_bounded_before_pagination_and_leaves_catalog_unchanged(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00Z'));
        $f = $this->fixtures();
        $f['record']->update(['source_updated_at' => now()->subHours(24), 'received_at' => now()]);
        for ($n = 0; $n < 26; $n++) {
            $copy = $f['record']->replicate();
            $copy->external_id = 'stale-'.$n;
            $copy->source_updated_at = now()->subHours(25);
            $copy->save();
        }
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $this->getJson($path.'?max_source_age_hours=24')->assertOk()->assertJsonPath('total', 2)
                ->assertJsonPath('last_page', 1)->assertJsonCount(2, 'data');
            $this->getJson($path.'?scope=partner&max_source_age_hours=1')->assertJsonPath('total', 0);
            foreach (['0', '169', '1.5', 'unknown'] as $invalid) {
                $this->getJson($path.'?max_source_age_hours='.$invalid)->assertUnprocessable();
            }
        }
        $f['record']->update(['source_updated_at' => now()->addMinute()]);
        $this->getJson('/api/discovery/search?max_source_age_hours=24')->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'catalog');
        Http::assertNothingSent();
    }

    public function test_catalog_age_policy_is_shared_across_customer_search_and_saved_place_surfaces(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00Z'));
        config(['partner_catalog.max_age_seconds' => 60]);
        $f = $this->fixtures();
        $this->putJson('/api/roaming/favorites/'.$f['record']->id)->assertOk();
        foreach ([[-59, -59, 'within_policy'], [-60, 0, 'stale'], [0, -60, 'stale'], [1, 0, 'unknown'], [0, 1, 'unknown']] as [$source, $receipt, $state]) {
            $f['record']->update(['source_updated_at' => now()->addSeconds($source), 'received_at' => now()->addSeconds($receipt)]);
            foreach (['/api/discovery/search?scope=partner', '/api/v1/discovery/search?scope=partner', '/api/roaming/locations', '/api/roaming/favorites'] as $path) {
                $this->getJson($path)->assertOk()->assertJsonPath('data.0.catalog_age_policy.state', $state)
                    ->assertJsonPath('data.0.catalog_age_policy.max_age_seconds', 60)
                    ->assertJsonPath('data.0.catalog_age_policy.physical_availability_verified', false)
                    ->assertJsonPath('data.0.remote_control', false)->assertJsonPath('data.0.reservable', false);
            }
        }
        config(['partner_catalog.max_age_seconds' => false]);
        $this->getJson('/api/discovery/search?scope=partner')->assertJsonPath('data.0.catalog_age_policy.state', 'policy_unavailable')
            ->assertJsonPath('data.0.catalog_age_policy.max_age_seconds', null);
        $f['provider']->update(['authorized' => false]);
        $this->getJson('/api/roaming/favorites')->assertJsonPath('data.0.catalog_age_policy', null);
        Http::assertNothingSent();
    }

    public function test_unified_routes_require_identity_without_expanding_the_public_surface(): void
    {
        $this->getJson('/api/discovery/search')->assertUnauthorized();
        $this->getJson('/api/v1/discovery/search')->assertUnauthorized();
    }

    public function test_unified_typed_results_preserve_source_gates_and_public_projection(): void
    {
        $f = $this->fixtures();
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $reply = $this->getJson($path.'?q=Shared&connector=CCS2')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('total', 2)->assertJsonCount(2, 'data');
            $rows = collect($reply->json('data'))->keyBy('kind');
            $this->assertSame('catalog:'.$f['station']->id, $rows['catalog']['key']);
            $this->assertSame('partner:'.$f['record']->id, $rows['partner']['key']);
            $this->assertFalse($rows['partner']['reservable']);
            $this->assertFalse($rows['partner']['remote_control']);
            $this->assertFalse($rows['partner']['location']['remote_control']);
            $this->assertSame('unknown', $rows['partner']['availability']);
            $this->assertSame('PARTNER_REPORTED_UNVERIFIED', $rows['partner']['evidence']);
            foreach (['UNIFIED-PRIVATE-PAYLOAD', 'PRIVATE-NORMALIZED', 'private-unified-key', 'content_hash', 'payload'] as $secret) {
                $this->assertStringNotContainsString($secret, $reply->getContent());
            }
        }
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_authorization_publication_module_and_source_scope_are_rechecked(): void
    {
        $f = $this->fixtures();
        $this->getJson('/api/discovery/search?scope=catalog')->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'catalog');
        $this->getJson('/api/discovery/search?scope=partner')->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'partner');
        $f['record']->update(['normalized' => ['publish' => false]]);
        $this->getJson('/api/discovery/search')->assertJsonPath('total', 1);
        $f['record']->update(['normalized' => ['publish' => true], 'module' => 'tariffs']);
        $this->getJson('/api/discovery/search')->assertJsonPath('total', 1);
        $f['record']->update(['module' => 'locations']);
        $f['provider']->update(['authorized' => false]);
        $this->getJson('/api/discovery/search?scope=partner')->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_literal_queries_operator_search_connector_normalization_and_validation(): void
    {
        $f = $this->fixtures();
        $this->getJson('/api/discovery/search?q='.urlencode('100%_hub'))->assertJsonPath('total', 2);
        $this->getJson('/api/discovery/search?q='.urlencode('100%_missing'))->assertJsonPath('total', 0);
        $this->getJson('/api/discovery/search?q=operator')->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'partner');
        $this->getJson('/api/discovery/search?connector=Type2')->assertJsonPath('total', 0);
        foreach (['scope=private', 'connector=CCS3', 'page=0', 'page=1000001', 'page=1.5', 'q='.str_repeat('x', 81)] as $query) {
            $this->getJson('/api/discovery/search?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/stations?station_id='.$f['station']->id)->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $f['station']->id);
    }

    public function test_unified_saved_places_use_only_the_current_customers_existing_preferences(): void
    {
        $f = $this->fixtures();
        $customer = auth()->user();
        $other = User::factory()->create();
        DB::table('station_favorites')->insert(['user_id' => $other->id, 'station_id' => $f['station']->id, 'created_at' => now()]);
        DB::table('partner_location_favorites')->insert(['user_id' => $other->id, 'record_id' => $f['record']->id, 'created_at' => now()]);
        $this->getJson('/api/discovery/search?saved_only=1')->assertOk()->assertJsonPath('total', 0);
        $this->putJson('/api/favorites/'.$f['station']->id)->assertOk();
        $this->putJson('/api/roaming/favorites/'.$f['record']->id)->assertOk();
        $reply = $this->getJson('/api/discovery/search?saved_only=1')->assertJsonPath('total', 2)->assertJsonPath('data.0.is_favorite', true)->assertJsonPath('data.1.is_favorite', true);
        $this->assertStringNotContainsString($other->email, $reply->getContent());
        $this->getJson('/api/v1/discovery/search?saved_only=1&scope=partner')->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'partner');
        $f['provider']->update(['authorized' => false]);
        $this->getJson('/api/discovery/search?saved_only=1')->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'catalog');
        $this->deleteJson('/api/favorites/'.$f['station']->id)->assertOk();
        $this->deleteJson('/api/roaming/favorites/'.$f['record']->id)->assertOk();
        $this->getJson('/api/discovery/search?saved_only=1')->assertJsonPath('total', 0);
        $this->assertDatabaseHas('station_favorites', ['user_id' => $other->id, 'station_id' => $f['station']->id]);
        $this->assertDatabaseHas('partner_location_favorites', ['user_id' => $other->id, 'record_id' => $f['record']->id]);
        $this->assertDatabaseMissing('station_favorites', ['user_id' => $customer->id]);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_saved_filter_rejects_other_roles_and_stale_request_accounts(): void
    {
        $f = $this->fixtures();
        $this->getJson('/api/discovery/search?saved_only=bad')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'vendor']));
        $this->getJson('/api/discovery/search')->assertOk();
        $this->getJson('/api/discovery/search?saved_only=1')->assertForbidden();
        $customer = User::factory()->create();
        $request = Request::create('/api/discovery/search', 'GET', ['saved_only' => '1']);
        $request->setUserResolver(fn () => $customer);
        User::whereKey($customer->id)->update(['auth_epoch' => $customer->auth_epoch + 1]);
        try {
            app(UnifiedDiscoveryController::class)->index($request);
            $this->fail('Revoked snapshot returned private preferences.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_union_pagination_is_bounded_and_stable_with_colliding_ids(): void
    {
        $f = $this->fixtures();
        for ($i = 0; $i < 28; $i++) {
            $copy = $f['station']->replicate();
            $copy->name = 'Page '.$i;
            $copy->save();
            $record = $f['record']->replicate();
            $record->external_id = 'page-'.$i;
            $record->save();
        }
        DB::enableQueryLog();
        $first = $this->getJson('/api/discovery/search')->assertOk()->assertJsonPath('total', 58)->assertJsonCount(25, 'data')->json();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(14, $queries);
        $second = $this->getJson('/api/discovery/search?page=2')->assertJsonCount(25, 'data')->json();
        $third = $this->getJson('/api/discovery/search?page=3')->assertJsonCount(8, 'data')->json();
        $keys = array_column([...$first['data'], ...$second['data'], ...$third['data']], 'key');
        $this->assertCount(58, array_unique($keys));
        $this->assertSame(array_column($first['data'], 'key'), array_column($this->getJson('/api/discovery/search')->json('data'), 'key'));
        $this->assertSame(['catalog', 'partner'], collect([...$first['data'], ...$second['data'], ...$third['data']])->pluck('kind')->unique()->sort()->values()->all());
    }

    public function test_explicit_text_filters_intersect_before_union_pagination_and_escape_wildcards(): void
    {
        $f = $this->fixtures();
        DB::table('station_information')->insert(['station_id' => $f['station']->id, 'operator_name' => 'Shared operator', 'address_en' => '100%_hub Dubai', 'address_ar' => 'دبي', 'access_en' => 'Visitors only', 'access_ar' => 'للزوار', 'weekly_hours' => '[]', 'closed_dates' => '[]', 'amenities' => '[]', 'accessibility' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        for ($n = 0; $n < 26; $n++) {
            $copy = $f['record']->replicate();
            $copy->external_id = 'unmatched-'.$n;
            $copy->normalized = ['publish' => true, 'address' => '100XXhub', 'city' => 'Elsewhere'];
            $copy->save();
        }
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $this->getJson($path.'?'.http_build_query(['operator' => 'Shared operator', 'address' => '100%_hub']))->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 1);
            $this->getJson($path.'?'.http_build_query(['operator' => 'missing', 'q' => 'Shared']))->assertJsonPath('total', 0);
            $this->getJson($path.'?'.http_build_query(['access' => 'للزوار']))->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'catalog');
            $this->getJson($path.'?scope=partner&access=Visitors')->assertJsonPath('total', 0);
            $this->getJson($path.'?'.http_build_query(['address' => 'دبي']))->assertJsonPath('total', 1);
            foreach (['operator' => 101, 'address' => 151, 'access' => 151] as $key => $length) {
                $this->getJson($path.'?'.http_build_query([$key => str_repeat('x', $length)]))->assertUnprocessable();
            }
        }
        Http::assertNothingSent();
    }

    public function test_unified_price_uses_exact_current_simulated_rate_and_never_partner_or_unknown_prices(): void
    {
        $this->freezeTime();
        $f = $this->fixtures();
        $tariff = fn (int $rate, $at) => TariffVersion::create(['station_id' => $f['station']->id, 'created_by' => $f['station']->vendor_id, 'rate_minor_per_kwh' => $rate, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => $at]);
        $tariff(1, now()->subHour());
        $tariff(150, now()->subMinute());
        $tariff(900, now()->addMinute());
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $this->getJson($path.'?max_rate_per_kwh=1.50&price_currency=AED')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'catalog')->assertJsonPath('data.0.station.id', $f['station']->id);
            $this->getJson($path.'?max_rate_per_kwh=1.49&price_currency=AED')->assertJsonPath('total', 0);
            $this->getJson($path.'?scope=partner&max_rate_per_kwh=2&price_currency=AED')->assertJsonPath('total', 0);
            foreach (['price_currency=AED', 'max_rate_per_kwh=1', 'max_rate_per_kwh=1.501&price_currency=AED', 'max_rate_per_kwh=1e2&price_currency=AED', 'max_rate_per_kwh=1&price_currency=ZZZ', 'max_rate_per_kwh[]=1&price_currency=AED'] as $bad) {
                $this->getJson($path.'?'.$bad)->assertUnprocessable();
            }
        }
        $f['station']->update(['source' => 'discovery']);
        $this->getJson('/api/discovery/search?max_rate_per_kwh=2&price_currency=AED')->assertJsonPath('total', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertNothingSent();
    }
}
