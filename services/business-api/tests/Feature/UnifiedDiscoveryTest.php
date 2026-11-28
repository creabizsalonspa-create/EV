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

    public function test_sqlite_source_age_filters_reject_malformed_storage_and_keep_exact_boundaries(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('PostgreSQL uses a typed source timestamp.');
        }
        $this->travelTo(Carbon::parse('2026-11-15T12:00:00.500000Z'));
        $record = $this->fixtures()['record'];
        foreach ([
            ['2026-11-15 11:30:99', 0],
            ['2026-11-15 11:30:00.invalid', 0],
            ['2026-11-15 11:30:00.1234567', 0],
            ['2026-11-15 11:30:00+04:00', 0],
            ['2026-11-15 11:30:00', 1],
            ['2026-11-15T11:30:00Z', 1],
            ['2026-11-15 12:00:00.5', 1],
            ['2026-11-15 12:00:00.500001', 0],
            ['2026-11-15T11:00:00.500000Z', 1],
            ['2026-11-15 11:00:00.499999', 0],
        ] as [$source, $count]) {
            DB::table('ocpi_records')->where('id', $record->id)->update(['source_updated_at' => $source]);
            foreach (['/api/discovery/search?scope=partner&', '/api/v1/discovery/search?scope=partner&', '/api/roaming/locations?'] as $path) {
                $this->getJson($path.'max_source_age_hours=1')->assertOk()->assertJsonPath('total', $count)
                    ->assertJsonCount($count, 'data');
            }
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_partner_age_filter_uses_utc_even_on_non_utc_application_host(): void
    {
        $original = date_default_timezone_get();
        $originalConfig = config('app.timezone');
        try {
            date_default_timezone_set('Asia/Dubai');
            config(['app.timezone' => 'Asia/Dubai']);
            $this->travelTo(Carbon::parse('2026-11-15T12:00:00.500000Z'));
            $f = $this->fixtures();
            foreach ([
                ['2026-11-15T12:00:00.500000Z', 1],
                ['2026-11-15T12:00:00.500001Z', 0],
                ['2026-11-15T11:00:00.500000Z', 1],
                ['2026-11-15T11:00:00.499999Z', 0],
            ] as [$source, $count]) {
                $f['record']->update(['source_updated_at' => Carbon::parse($source)]);
                foreach (['/api/discovery/search?scope=partner&', '/api/v1/discovery/search?scope=partner&', '/api/roaming/locations?'] as $path) {
                    $response = $this->getJson($path.'max_source_age_hours=1')->assertOk()->assertJsonPath('total', $count);
                    if ($count === 1) {
                        $response->assertJsonPath('data.0.catalog_checked_at', '2026-11-15T12:00:00.500000Z')
                            ->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false);
                    }
                }
            }
        } finally {
            $this->travelBack();
            date_default_timezone_set($original);
            config(['app.timezone' => $originalConfig]);
        }
        Http::assertNothingSent();
    }

    public function test_partner_reported_status_and_connector_filter_must_match_one_evse(): void
    {
        $f = $this->fixtures();
        $record = $f['record'];
        $normalized = $record->normalized;
        $normalized['connector_standards'] = ['IEC_62196_T2_COMBO', 'CHADEMO'];
        $normalized['evses'] = [
            ['uid' => 'available', 'reported_status' => 'AVAILABLE', 'connectors' => [['id' => 'a', 'standard' => 'CHADEMO']]],
            ['uid' => 'charging', 'reported_status' => 'CHARGING', 'connectors' => [['id' => 'b', 'standard' => 'IEC_62196_T2_COMBO']]],
        ];
        $record->update(['normalized' => $normalized]);
        $url = '/api/roaming/locations?';
        $this->getJson($url.'reported_evse_status=AVAILABLE&connector_standard=IEC_62196_T2_COMBO')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'reported_evse_status=AVAILABLE&connector_standard=CHADEMO')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false);
        $this->getJson($url.'reported_evse_status=CHARGING&connector_standard=IEC_62196_T2_COMBO')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url.'connector_standard=IEC_62196_T2_COMBO')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url.'reported_evse_status=AVAILABLE')->assertOk()->assertJsonPath('total', 1);
        $normalized['evses'] = ['malformed-legacy-value', ['uid' => 'broken', 'reported_status' => 'AVAILABLE', 'connectors' => 'invalid']];
        $record->update(['normalized' => $normalized]);
        $this->getJson($url.'reported_evse_status=AVAILABLE&connector_standard=CHADEMO')->assertOk()->assertJsonPath('total', 0);
        $normalized['evses'] = 'malformed-legacy-value';
        $record->update(['normalized' => $normalized]);
        $this->getJson($url.'reported_evse_status=AVAILABLE')->assertOk()->assertJsonPath('total', 0);
        Http::assertNothingSent();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_reported_state_search_cannot_match_redacted_evse_or_connector(): void
    {
        $record = $this->fixtures()['record'];
        $normalized = $record->normalized;
        $normalized['connector_standards'] = ['CHADEMO'];
        $evse = ['uid' => 'valid', 'reported_status' => 'AVAILABLE', 'connectors' => [
            ['id' => 'valid', 'standard' => 'CHADEMO'],
        ]];
        $url = '/api/roaming/locations?reported_evse_status=AVAILABLE';
        foreach ([null, ['private' => 'hidden'], true, '', str_repeat('x', 65), "bad\nuid"] as $invalid) {
            $normalized['evses'] = [array_replace($evse, ['uid' => $invalid])];
            $record->update(['normalized' => $normalized]);
            $this->getJson($url)->assertOk()->assertJsonPath('total', 0);
            $normalized['evses'] = [array_replace($evse, ['connectors' => [
                ['id' => $invalid, 'standard' => 'CHADEMO'],
            ]])];
            $record->update(['normalized' => $normalized]);
            $this->getJson($url.'&connector_standard=CHADEMO')->assertOk()->assertJsonPath('total', 0);
        }
        $normalized['evses'] = [...array_fill(0, 100, null), $evse];
        $record->update(['normalized' => $normalized]);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 0);
        $normalized['evses'] = [array_replace($evse, ['connectors' => [
            ...array_fill(0, 20, null), $evse['connectors'][0],
        ]])];
        $record->update(['normalized' => $normalized]);
        $this->getJson($url.'&connector_standard=CHADEMO')->assertOk()->assertJsonPath('total', 0);
        $normalized['evses'] = [...array_fill(0, 99, null), array_replace($evse, ['connectors' => [
            ...array_fill(0, 19, null), $evse['connectors'][0],
        ]])];
        $record->update(['normalized' => $normalized]);
        $this->getJson($url.'&connector_standard=CHADEMO')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_unified_partner_state_filter_matches_the_same_evse_before_pagination(): void
    {
        $f = $this->fixtures();
        $record = $f['record'];
        $normalized = $record->normalized;
        $normalized['connector_standards'] = ['IEC_62196_T2_COMBO', 'CHADEMO'];
        $normalized['evses'] = [
            ['uid' => 'available', 'reported_status' => 'AVAILABLE', 'connectors' => [['id' => 'a', 'standard' => 'CHADEMO']]],
            ['uid' => 'charging', 'reported_status' => 'CHARGING', 'connectors' => [['id' => 'b', 'standard' => 'IEC_62196_T2_COMBO']]],
        ];
        $record->update(['normalized' => $normalized]);
        for ($n = 0; $n < 26; $n++) {
            $copy = $record->replicate();
            $copy->external_id = 'different-evse-state-'.$n;
            $copy->normalized = array_replace($normalized, ['evses' => [$normalized['evses'][1]]]);
            $copy->save();
        }

        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $url) {
            $this->getJson($url.'?reported_evse_status=AVAILABLE&connector=CHAdeMO')->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('last_page', 1)
                ->assertJsonPath('data.0.key', 'partner:'.$record->id)
                ->assertJsonPath('data.0.availability', 'unknown')
                ->assertJsonPath('data.0.reservable', false)
                ->assertJsonPath('data.0.remote_control', false);
            $this->getJson($url.'?reported_evse_status=AVAILABLE&connector=CCS2')->assertOk()
                ->assertJsonPath('total', 0);
            $this->getJson($url.'?reported_evse_status=AVAILABLE&scope=catalog')->assertOk()
                ->assertJsonPath('total', 0);
            $this->getJson($url.'?reported_evse_status=AVAILABLE&recent_source_status=Available')->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'partner');
            $this->getJson($url.'?reported_evse_status=available')
                ->assertUnprocessable()->assertJsonValidationErrors('reported_evse_status');
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

    public function test_partner_text_and_connector_search_cannot_match_nested_private_projection_data(): void
    {
        $f = $this->fixtures();
        $record = $f['record'];
        $original = $record->normalized;
        $private = ['private' => 'private-hidden-value'];
        $record->update(['normalized' => array_replace($original, [
            'name' => $private, 'address' => $private, 'city' => $private,
            'connector_standards' => ['private' => 'IEC_62196_T2_COMBO'],
        ])]);
        foreach (['/api/discovery/search?scope=partner&', '/api/v1/discovery/search?scope=partner&'] as $path) {
            $this->getJson($path.'q=private-hidden-value')->assertOk()->assertJsonPath('total', 0);
            $this->getJson($path.'address=private-hidden-value')->assertOk()->assertJsonPath('total', 0);
            $this->getJson($path.'connector=CCS2')->assertOk()->assertJsonPath('total', 0);
        }
        $this->getJson('/api/roaming/locations?q=private-hidden-value')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/roaming/locations?connector_standard=IEC_62196_T2_COMBO')->assertOk()->assertJsonPath('total', 0);
        $record->update(['normalized' => array_replace($original, ['connector_standards' => 'IEC_62196_T2_COMBO'])]);
        $this->getJson('/api/discovery/search?scope=partner&connector=CCS2')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/roaming/locations?connector_standard=IEC_62196_T2_COMBO')->assertOk()->assertJsonPath('total', 0);
        $record->update(['normalized' => $original]);
        $this->getJson('/api/discovery/search?scope=partner&q=Shared&connector=CCS2')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/roaming/locations?q=Shared&connector_standard=IEC_62196_T2_COMBO')->assertOk()->assertJsonPath('total', 1);
        Http::assertNothingSent();
    }

    public function test_partner_search_rejects_control_bearing_public_text(): void
    {
        $f = $this->fixtures();
        $record = $f['record'];
        $original = $record->normalized;
        $controls = ["\x01", "\x1f", "\x7f", "\n", "\t"];
        if (DB::connection()->getDriverName() === 'sqlite') {
            // PostgreSQL text does not support an embedded zero byte.
            $controls[] = "\x00";
        }
        foreach ($controls as $control) {
            $hidden = 'concealed-search-marker'.$control.'suffix';
            $record->update(['normalized' => array_replace($original, [
                'name' => $hidden, 'city' => $hidden, 'address' => $hidden,
            ])]);
            foreach (['/api/discovery/search?scope=partner&', '/api/v1/discovery/search?scope=partner&'] as $path) {
                $this->getJson($path.'q=concealed-search-marker')->assertOk()->assertJsonPath('total', 0);
                $this->getJson($path.'address=concealed-search-marker')->assertOk()->assertJsonPath('total', 0);
            }
            $this->getJson('/api/roaming/locations?q=concealed-search-marker')->assertOk()->assertJsonPath('total', 0);
        }
        $record->update(['normalized' => $original]);
        $this->getJson('/api/discovery/search?scope=partner&q=Shared')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/discovery/search?scope=partner&q=Shared')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/roaming/locations?q=Shared')->assertOk()->assertJsonPath('total', 1);
        Http::assertNothingSent();
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

    public function test_exact_map_view_filters_both_sources_before_union_pagination_without_provider_calls(): void
    {
        $f = $this->fixtures();
        $f['record']->update(['normalized' => array_merge($f['record']->normalized, [
            'latitude' => '25.1', 'longitude' => '55.1',
        ])]);
        $excluded = Station::create([
            'vendor_id' => $f['station']->vendor_id, 'name' => 'Outside view',
            'source' => 'discovery', 'country' => 'AE', 'currency' => 'AED',
            'timezone' => 'Asia/Dubai', 'latitude' => 40, 'longitude' => 70,
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        // Newer records outside the viewport must not consume page slots.
        foreach (range(1, 26) as $i) {
            Station::create([
                'vendor_id' => $f['station']->vendor_id, 'name' => 'Outside '.$i,
                'source' => 'discovery', 'country' => 'AE', 'currency' => 'AED',
                'timezone' => 'Asia/Dubai', 'latitude' => 45, 'longitude' => 70,
                'connector_type' => 'CCS2', 'power_kw' => 50,
            ]);
        }
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $route) {
            $query = '?south=24&north=26&west=54&east=56';
            $response = $this->getJson($route.$query)->assertOk()
                ->assertJsonPath('total', 2)->assertJsonCount(2, 'data')
                ->assertJsonPath('current_page', 1)
                ->assertHeader('Cache-Control');
            $directives = array_map('trim', explode(',', strtolower((string) $response->headers->get('Cache-Control'))));
            $this->assertContains('private', $directives, 'Discovery must be private.');
            $this->assertContains('no-store', $directives, 'Discovery must never be cached publicly.');
            $keys = array_column($response->json('data'), 'key');
            $this->assertEqualsCanonicalizing([
                'catalog:'.$f['station']->id, 'partner:'.$f['record']->id,
            ], $keys);
            $this->assertNotContains('catalog:'.$excluded->id, $keys);
            $this->getJson($route.$query.'&scope=partner')
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.availability', 'unknown')
                ->assertJsonPath('data.0.reservable', false)->assertJsonPath('data.0.remote_control', false);
            $this->getJson($route.$query.'&scope=catalog')->assertJsonPath('total', 1);
            $this->getJson($route.'?south=25.05&north=25.2&west=55.05&east=55.2')
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'partner');
            $this->getJson($route.'?south=24&north=26&west=54&east=56&page=2')
                ->assertJsonPath('total', 2)->assertJsonCount(0, 'data');
        }
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertNothingSent();
    }

    public function test_map_view_antimeridian_and_missing_coordinates_do_not_produce_false_pins(): void
    {
        $f = $this->fixtures();
        $f['station']->update(['latitude' => 10, 'longitude' => -179.8]);
        $f['record']->update(['normalized' => array_merge($f['record']->normalized, [
            'latitude' => '10.1', 'longitude' => '179.8',
        ])]);
        $other = Station::create([
            'vendor_id' => $f['station']->vendor_id, 'name' => 'Greenwich station',
            'source' => 'discovery', 'country' => 'AE', 'currency' => 'AED',
            'timezone' => 'Asia/Dubai', 'latitude' => 10, 'longitude' => 0,
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $missing = OcpiRecord::create([
            'ocpi_provider_id' => $f['provider']->id, 'module' => 'locations',
            'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'no-coordinates',
            'payload' => ['secret' => 'never project'], 'content_hash' => str_repeat('b', 64),
            'normalized' => ['name' => 'No coordinates', 'publish' => true, 'latitude' => null, 'longitude' => null],
            'source_updated_at' => now(), 'received_at' => now(),
        ]);
        $malformed = OcpiRecord::create([
            'ocpi_provider_id' => $f['provider']->id, 'module' => 'locations',
            'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'bad-coordinates',
            'payload' => ['secret' => 'never project'], 'content_hash' => str_repeat('c', 64),
            'normalized' => ['name' => 'Malformed location', 'publish' => true, 'latitude' => 'not-a-number', 'longitude' => '0'],
            'source_updated_at' => now(), 'received_at' => now(),
        ]);
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $route) {
            $url = $route.'?south=9&north=11&west=179&east=-179';
            $response = $this->getJson($url)->assertOk()->assertJsonPath('total', 2);
            $this->assertEqualsCanonicalizing([
                'catalog:'.$f['station']->id, 'partner:'.$f['record']->id,
            ], array_column($response->json('data'), 'key'));
            $this->assertNotContains('catalog:'.$other->id, array_column($response->json('data'), 'key'));
            $this->assertNotContains('partner:'.$missing->id, array_column($response->json('data'), 'key'));
            $this->assertNotContains('partner:'.$malformed->id, array_column($response->json('data'), 'key'));
            $this->getJson($route.'?south=9&north=11&west=-180&east=180')
                ->assertJsonPath('total', 3);
            $this->getJson($route.'?south=9&north=11&west=-1&east=1')
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'catalog');
        }
        Http::assertNothingSent();
    }

    public function test_partner_viewport_and_radius_reject_invalid_coordinates_consistently(): void
    {
        $f = $this->fixtures();
        $record = $f['record'];
        $normalized = array_replace($record->normalized, ['latitude' => '0', 'longitude' => '0']);
        $record->update(['normalized' => $normalized]);
        foreach ([
            ['latitude' => 'invalid'], ['longitude' => true], ['latitude' => false],
            ['longitude' => '360'], ['latitude' => '91'], ['longitude' => '1e999'],
            ['latitude' => ['0']], ['longitude' => null],
            ['latitude' => '0e000'], ['longitude' => '0'.str_repeat('0', 40)],
        ] as $i => $change) {
            $copy = $record->replicate();
            $copy->external_id = 'invalid-coordinate-'.$i;
            $copy->normalized = array_replace($normalized, $change);
            $copy->save();
        }
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $path) {
            $this->getJson($path.'?scope=partner&south=-1&north=1&west=-1&east=1')
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.record_id', $record->id);
        }
        $this->getJson('/api/roaming/locations?latitude=0&longitude=0&radius_km=10')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $record->id)
            ->assertJsonPath('data.0.distance_km', 0)->assertJsonPath('data.0.availability', 'unknown');
        Http::assertNothingSent();
    }

    public function test_map_view_requires_complete_finite_and_nondegenerate_bounds(): void
    {
        $this->fixtures();
        $invalid = [
            'south=24', 'south=24&north=26&west=54',
            'south=26&north=24&west=54&east=56',
            'south=24&north=24&west=54&east=56',
            'south=24&north=26&west=54&east=54',
            'south=-91&north=26&west=54&east=56',
            'south=24&north=91&west=54&east=56',
            'south=24&north=26&west=-181&east=56',
            'south=24&north=26&west=54&east=181',
            'south=24&north=26&west=NaN&east=56',
            'south[]=24&north=26&west=54&east=56',
        ];
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $route) {
            foreach ($invalid as $query) {
                $this->getJson($route.'?'.$query)->assertUnprocessable();
            }
        }
        Http::assertNothingSent();
    }

    public function test_supported_country_filters_exact_location_not_ocpi_party_before_pagination(): void
    {
        $f = $this->fixtures();

        $usStation = $f['station']->replicate();
        $usStation->country = 'US';
        $usStation->currency = 'USD';
        $usStation->name = 'United States station';
        $usStation->save();

        $canadaStation = $f['station']->replicate();
        $canadaStation->country = 'CA';
        $canadaStation->currency = 'CAD';
        $canadaStation->name = 'Canada station';
        $canadaStation->save();

        $usPartner = $f['record']->replicate();
        $usPartner->external_id = 'us-location-from-ca-party';
        $usPartner->country_code = 'CA'; // Party registration is NOT the station country.
        $usPartner->content_hash = str_repeat('b', 64);
        $usPartner->normalized = array_merge($f['record']->normalized, [
            'name' => 'United States partner', 'country' => 'USA',
            'latitude' => '25.1', 'longitude' => '55.1',
        ]);
        $usPartner->save();

        $canadaPartner = $f['record']->replicate();
        $canadaPartner->external_id = 'ca-location-from-ae-party';
        $canadaPartner->country_code = 'AE';
        $canadaPartner->content_hash = str_repeat('c', 64);
        $canadaPartner->normalized = array_merge($f['record']->normalized, [
            'name' => 'Canada partner', 'country' => 'CAN',
            'latitude' => '25.2', 'longitude' => '55.2',
        ]);
        $canadaPartner->save();

        // Out-of-country high IDs must not consume the target country's page.
        foreach (range(1, 26) as $n) {
            $out = $f['station']->replicate();
            $out->name = 'Other-market-'.$n;
            $out->save();
        }

        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $route) {
            foreach ([
                'AE' => ['catalog:'.$f['station']->id, 'partner:'.$f['record']->id],
                'US' => ['catalog:'.$usStation->id, 'partner:'.$usPartner->id],
                'CA' => ['catalog:'.$canadaStation->id, 'partner:'.$canadaPartner->id],
            ] as $country => $expected) {
                $reply = $this->getJson($route.'?country='.$country)->assertOk()
                    ->assertJsonPath('total', $country === 'AE' ? 28 : 2);
                // The AE fixtures span two pages: out-of-country US/CA
                // records must not consume page slots in their own searches.
                $allKeys = array_column($reply->json('data'), 'key');
                if ($country === 'AE') {
                    $reply->assertJsonPath('last_page', 2);
                    $second = $this->getJson($route.'?country=AE&page=2')->assertOk()->assertJsonPath('total', 28);
                    $allKeys = array_merge($allKeys, array_column($second->json('data'), 'key'));
                }
                $this->assertEqualsCanonicalizing(
                    $expected,
                    array_values(array_intersect($expected, $allKeys))
                );
                if ($country !== 'AE') {
                    $reply->assertJsonCount(2, 'data')->assertJsonPath('last_page', 1);
                    $this->getJson($route.'?country='.$country.'&scope=partner')
                        ->assertJsonPath('total', 1)->assertJsonPath('data.0.availability', 'unknown')
                        ->assertJsonPath('data.0.reservable', false);
                    $this->getJson($route.'?country='.$country.'&scope=catalog')
                        ->assertJsonPath('total', 1);
                }
            }
            $this->getJson($route.'?country=US&south=24&north=26&west=54&east=56')
                ->assertJsonPath('total', 2);
            $this->getJson($route.'?country=US&page=2')->assertJsonPath('total', 2)->assertJsonCount(0, 'data');
            foreach (['GB', 'USA', 'ae', 'XX', 'AE%25', 'country[]=US'] as $bad) {
                $suffix = str_starts_with($bad, 'country[') ? $bad : 'country='.$bad;
                $this->getJson($route.'?'.$suffix)->assertUnprocessable();
            }
        }
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('reservations', 0);
        Http::assertNothingSent();
    }

    public function test_bounded_published_station_power_filters_before_union_without_trusting_partner_reports(): void
    {
        $f = $this->fixtures();
        $low = $f['station']->replicate();
        $low->name = 'Low rated 7 kW';
        $low->power_kw = 7;
        $low->save();

        $high = $f['station']->replicate();
        $high->name = 'High rated US 150 kW';
        $high->power_kw = 150;
        $high->country = 'US';
        $high->currency = 'USD';
        $high->save();

        // Partner's reported capabilities are not a canonical station rating.
        $f['record']->update(['normalized' => array_merge($f['record']->normalized, [
            'latitude' => '25', 'longitude' => '55',
            'evses' => [['reported_status' => 'AVAILABLE', 'connectors' => [['standard' => 'IEC_62196_T2_COMBO', 'max_electric_power' => 350000]]]],
        ])]);

        // Newer out-of-range stations must not consume a page slot.
        foreach (range(1, 27) as $i) {
            $other = $f['station']->replicate();
            $other->name = 'Outside power range '.$i;
            $other->power_kw = 1;
            $other->save();
        }

        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $route) {
            $middle = $this->getJson($route.'?min_power_kw=40&max_power_kw=60')
                ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.key', 'catalog:'.$f['station']->id)
                ->assertJsonPath('data.0.station.power_kw', 50);
            $this->assertStringNotContainsString('max_electric_power', $middle->getContent());

            $this->getJson($route.'?min_power_kw=100')
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.key', 'catalog:'.$high->id);
            $this->getJson($route.'?min_power_kw=100&country=US&south=24&north=26&west=54&east=56')
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.key', 'catalog:'.$high->id);
            $this->getJson($route.'?max_power_kw=7')
                ->assertJsonPath('total', 28)->assertJsonPath('last_page', 2);
            $this->getJson($route.'?min_power_kw=40&max_power_kw=60&page=2')
                ->assertJsonPath('total', 1)->assertJsonCount(0, 'data');
            $this->getJson($route.'?scope=partner&min_power_kw=1')
                ->assertJsonPath('total', 0);
            foreach ([
                'min_power_kw=0', 'min_power_kw=1001', 'max_power_kw=0',
                'min_power_kw=1.5', 'max_power_kw=-3', 'min_power_kw=NaN',
                'min_power_kw=65&max_power_kw=40', 'min_power_kw[]=40',
            ] as $invalid) {
                $this->getJson($route.'?'.$invalid)->assertUnprocessable();
            }
        }

        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        Http::assertNothingSent();
    }
}
