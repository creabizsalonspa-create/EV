<?php

namespace Tests\Feature;

use App\Http\Controllers\UnifiedDiscoveryController;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
}
