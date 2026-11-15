<?php

namespace Tests\Feature;

use App\Http\Controllers\PartnerLocationFavoriteController;
use App\Http\Controllers\StationFavoriteController;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\Station;
use App\Models\User;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PartnerLocationFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function location(bool $publish = true): array
    {
        $provider = OcpiProvider::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Private source operator', 'connection_key' => 'favorites-'.bin2hex(random_bytes(4)), 'authorized' => true, 'version' => '2.2.1']);
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'location-1', 'publish' => $publish, 'name' => 'Partner place', 'address' => 'Public source address', 'city' => 'Dubai', 'country' => 'ARE', 'coordinates' => ['latitude' => '25.2', 'longitude' => '55.3'], 'last_updated' => now()->subMinute()->toISOString(), 'private_token' => 'NEVER-DISPLAY']]);

        return [$provider, OcpiRecord::where('ocpi_provider_id', $provider->id)->firstOrFail()];
    }

    public function test_current_customer_authority_is_required_for_saved_place_actions(): void
    {
        Route::middleware(['web', 'auth', 'favorite-late-change'])->group(function () {
            foreach (['stations' => StationFavoriteController::class, 'partners' => PartnerLocationFavoriteController::class] as $kind => $controller) {
                Route::get('/favorite-late/'.$kind, [$controller, 'index']);
                Route::get('/favorite-late/'.$kind.'/status', [$controller, 'status']);
                Route::put('/favorite-late/'.$kind.'/{resource}', [$controller, 'save']);
                Route::delete('/favorite-late/'.$kind.'/{resource}', [$controller, 'remove']);
            }
        });
        foreach (['role', 'account_status', 'auth_epoch', 'password'] as $field) {
            [$provider, $record] = $this->location();
            $station = Station::create(['vendor_id' => $provider->owner_id, 'name' => 'Private saved place', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'discovery', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'connector_type' => 'CCS2', 'power_kw' => 50]);
            $user = User::factory()->create();
            DB::table('station_favorites')->insert(['user_id' => $user->id, 'station_id' => $station->id, 'created_at' => now()]);
            DB::table('partner_location_favorites')->insert(['user_id' => $user->id, 'record_id' => $record->id, 'created_at' => now()]);
            $changed = match ($field) {
                'role' => 'vendor', 'account_status' => 'suspended', 'auth_epoch' => 1, 'password' => bcrypt('ChangedSyntheticPassword9!')
            };
            $this->app->bind('favorite-late-change', fn () => new class($field, $changed)
            {
                public function __construct(private string $field, private mixed $value) {}

                public function handle(Request $request, \Closure $next)
                {
                    DB::table('users')->where('id', $request->user()->id)->update([$this->field => $this->value]);

                    return $next($request);
                }
            });
            $status = in_array($field, ['role', 'account_status'], true) ? 403 : 409;
            foreach (['stations' => $station->id, 'partners' => $record->id] as $kind => $id) {
                foreach ([['GET', '', []], ['GET', '/status', [$kind === 'stations' ? 'station_ids' : 'record_ids' => [$id]]], ['PUT', '/'.$id, []], ['DELETE', '/'.$id, []]] as [$method, $suffix, $body]) {
                    $this->actingAs($user)->json($method, '/favorite-late/'.$kind.$suffix, $body)->assertStatus($status)->assertJsonMissingPath('data');
                    $this->assertDatabaseHas('station_favorites', ['user_id' => $user->id, 'station_id' => $station->id]);
                    $this->assertDatabaseHas('partner_location_favorites', ['user_id' => $user->id, 'record_id' => $record->id]);
                }
            }
        }
    }

    public function test_private_customer_preferences_are_idempotent_isolated_and_never_added_to_public_catalog(): void
    {
        [, $location] = $this->location();
        $path = '/api/roaming/favorites/'.$location->id;
        $this->putJson($path)->assertUnauthorized();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->putJson($path)->assertOk()->assertJsonPath('remote_control', false);
        $this->putJson($path)->assertOk();
        $this->assertDatabaseCount('partner_location_favorites', 1);
        $this->postJson('/api/roaming/favorites/status', ['record_ids' => [$location->id]])->assertOk()->assertJsonPath('favorite_record_ids', [$location->id]);
        $this->getJson('/api/roaming/favorites')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.location_available', true)->assertDontSee('NEVER-DISPLAY')->assertJsonMissingPath('data.0.payload');
        $this->getJson('/api/roaming/locations')->assertOk()->assertJsonMissingPath('data.0.is_favorite')->assertJsonMissingPath('data.0.favorite_since');
        $other = User::factory()->create(['role' => 'customer']);
        $this->actingAs($other)->postJson('/api/roaming/favorites/status', ['record_ids' => [$location->id]])->assertJsonPath('favorite_record_ids', []);
        $this->getJson('/api/roaming/favorites')->assertJsonPath('total', 0);
        $this->deleteJson($path)->assertOk();
        $this->assertDatabaseCount('partner_location_favorites', 1);
        $this->actingAs($customer)->deleteJson($path)->assertOk();
        $this->deleteJson($path)->assertOk();
        $this->assertDatabaseCount('partner_location_favorites', 0);
    }

    public function test_revoked_and_unpublished_locations_are_redacted_but_can_still_be_removed(): void
    {
        [$provider, $location] = $this->location();
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $path = '/api/roaming/favorites/'.$location->id;
        $this->putJson($path)->assertOk();
        $provider->update(['authorized' => false]);
        $this->getJson('/api/roaming/favorites')->assertOk()->assertJsonPath('data.0.location_available', false)->assertJsonPath('data.0.location', null)->assertJsonPath('data.0.received_at', null)->assertDontSee('Public source address');
        $this->putJson($path)->assertNotFound();
        $this->deleteJson($path)->assertOk();
        [, $unpublished] = $this->location(false);
        $this->putJson('/api/roaming/favorites/'.$unpublished->id)->assertNotFound();
        $this->assertDatabaseCount('partner_location_favorites', 0);
    }

    public function test_saved_location_uses_the_same_strict_public_projection_and_age_evidence(): void
    {
        [$provider, $record] = $this->location();
        $normalized = $record->normalized;
        $normalized['internal_gateway_secret'] = 'SHOULD_NEVER_LEAK';
        $normalized['evses'] = [[
            'uid' => 'evse-private',
            'reported_status' => 'AVAILABLE',
            'internal_token' => 'SHOULD_NEVER_LEAK_NESTED',
            'connectors' => [['id' => 'c1', 'standard' => 'IEC_62196_T2_COMBO', 'secret' => 'SHOULD_NEVER_LEAK_CONNECTOR']],
        ]];
        $record->normalized = $normalized;
        $record->save();

        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->putJson('/api/roaming/favorites/'.$record->id)->assertOk();
        $response = $this->getJson('/api/roaming/favorites')->assertOk();
        $response->assertJsonPath('data.0.location.evses.0.uid', 'evse-private')
            ->assertJsonPath('data.0.location.evses.0.connectors.0.standard', 'IEC_62196_T2_COMBO')
            ->assertJsonPath('data.0.provider_label', 'Private source operator')
            ->assertJsonPath('data.0.provider_label_evidence', 'PROVIDER_CONFIGURED_UNVERIFIED')
            ->assertJsonPath('data.0.received_timestamp_evidence', 'LOCAL_INGESTION_TIME')
            ->assertJsonPath('data.0.source_timestamp_evidence', 'PARTNER_REPORTED_ONLY')
            ->assertJsonPath('data.0.location.availability', 'unknown')
            ->assertJsonMissingPath('data.0.location.internal_gateway_secret')
            ->assertJsonMissingPath('data.0.location.evses.0.internal_token')
            ->assertJsonMissingPath('data.0.location.evses.0.connectors.0.secret')
            ->assertDontSee('SHOULD_NEVER_LEAK');
        $provider->update(['authorized' => false]);
        $this->getJson('/api/roaming/favorites')->assertOk()
            ->assertJsonPath('data.0.location_available', false)
            ->assertJsonPath('data.0.location', null)
            ->assertJsonPath('data.0.provider_label', null)
            ->assertJsonPath('data.0.source_updated_at', null)
            ->assertJsonPath('data.0.received_at', null);
    }

    public function test_saved_partner_locations_are_stably_paginated_without_private_source_fields(): void
    {
        [$provider, $record] = $this->location();
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = array_replace($record->payload, ['id' => 'location-'.($i + 1)]);
        }
        app(RecordSynchronizer::class)->ingest($provider, 'locations', $rows);
        $customer = User::factory()->create(['role' => 'customer']);
        foreach (OcpiRecord::where('ocpi_provider_id', $provider->id)->pluck('id') as $id) {
            DB::table('partner_location_favorites')->insert(['user_id' => $customer->id, 'record_id' => $id, 'created_at' => now()]);
        }
        $first = $this->actingAs($customer)->getJson('/api/roaming/favorites')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 26);
        $second = $this->getJson('/api/roaming/favorites?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.payload')->assertDontSee('NEVER-DISPLAY');
        $this->assertNotContains($second->json('data.0.id'), array_column($first->json('data'), 'id'));
    }

    public function test_noncustomers_and_invalid_status_batches_cannot_inspect_or_mutate_preferences(): void
    {
        [, $location] = $this->location();
        foreach (['vendor', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/roaming/favorites')->assertForbidden();
            $this->putJson('/api/roaming/favorites/'.$location->id)->assertForbidden();
            $this->postJson('/api/roaming/favorites/status', ['record_ids' => [$location->id]])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'customer']))->postJson('/api/roaming/favorites/status', ['record_ids' => []])->assertUnprocessable();
        $this->postJson('/api/roaming/favorites/status', ['record_ids' => array_fill(0, 26, $location->id)])->assertUnprocessable();
        $this->putJson('/api/roaming/favorites/0')->assertUnprocessable();
        $this->assertDatabaseCount('partner_location_favorites', 0);
    }
}
