<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class StationFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function station(string $source = 'discovery'): Station
    {
        return Station::create(['vendor_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Saved station', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => $source, 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    public function test_customer_preferences_are_scoped_idempotent_and_never_grant_control(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $station = $this->station();
        $this->actingAs($a)->putJson('/api/favorites/'.$station->id, ['user_id' => $b->id])->assertOk()->assertJsonPath('is_favorite', true);
        $this->putJson('/api/favorites/'.$station->id)->assertOk();
        $this->assertDatabaseCount('station_favorites', 1);
        $this->getJson('/api/favorites')->assertOk()->assertJsonPath('data.0.id', $station->id)->assertJsonPath('data.0.is_favorite', true)->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.reservable', false)->assertJsonPath('data.0.remote_control', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/stations')->assertJsonMissingPath('data.0.is_favorite');
        $this->getJson('/api/favorites/status?station_ids[]='.$station->id)->assertJsonPath('favorite_station_ids.0', $station->id);
        $this->actingAs($b)->getJson('/api/favorites')->assertJsonCount(0, 'data');
        $this->getJson('/api/stations')->assertJsonMissingPath('data.0.is_favorite');
        $this->getJson('/api/favorites/status?station_ids[]='.$station->id)->assertJsonCount(0, 'favorite_station_ids');
        $this->deleteJson('/api/favorites/'.$station->id)->assertJsonPath('is_favorite', false);
        $this->assertDatabaseCount('station_favorites', 1);
        $this->actingAs($a)->deleteJson('/api/favorites/'.$station->id)->assertOk();
        $this->deleteJson('/api/favorites/'.$station->id)->assertOk();
        $this->assertDatabaseCount('station_favorites', 0);
    }

    public function test_guest_vendor_and_admin_cannot_read_or_change_customer_preferences(): void
    {
        $s = $this->station();
        $this->getJson('/api/favorites')->assertUnauthorized();
        $this->getJson('/api/favorites/status?station_ids[]=1')->assertUnauthorized();
        $this->putJson('/api/favorites/'.$s->id)->assertUnauthorized();
        foreach (['vendor', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/favorites')->assertForbidden();
            $this->getJson('/api/favorites/status?station_ids[]=1')->assertForbidden();
            $this->putJson('/api/favorites/'.$s->id)->assertForbidden();
            $this->deleteJson('/api/favorites/'.$s->id)->assertForbidden();
        }
        $this->assertDatabaseCount('station_favorites', 0);
    }

    public function test_favorites_paginate_with_stable_order_and_missing_stations_are_not_invented(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/api/favorites/999999')->assertNotFound();
        $this->putJson('/api/favorites/999999999999999999999999999999')->assertUnprocessable();
        $this->deleteJson('/api/favorites/0')->assertUnprocessable();
        for ($i = 0; $i < 26; $i++) {
            $s = $this->station();
            $this->putJson('/api/favorites/'.$s->id)->assertOk();
        }
        $this->getJson('/api/favorites')->assertJsonPath('total', 26)->assertJsonCount(25, 'data')->assertJsonPath('data.0.id', $s->id);
        $this->getJson('/api/favorites?page=2')->assertJsonCount(1, 'data');
        $s->delete();
        $this->assertDatabaseCount('station_favorites', 25);
        $this->deleteJson('/api/favorites/'.$s->id)->assertOk();
    }

    public function test_status_lookup_is_bounded_validated_and_never_exposes_another_customer(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['', '?station_ids=1', '?station_ids[]=0', '?station_ids[]=1&station_ids[]=1', '?'.http_build_query(['station_ids' => range(1, 26)])] as $query) {
            $this->getJson('/api/favorites/status'.$query)->assertUnprocessable();
        }
    }

    public function test_native_token_and_cookie_clients_share_favorites_without_cross_user_personalization(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $station = $this->station();
        $token = $user->createToken('favorite-test')->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/favorites/'.$station->id)->assertOk();
        $this->getJson('/api/v1/favorites')->assertJsonPath('data.0.id', $station->id);
        $this->getJson('/api/v1/stations')->assertJsonMissingPath('data.0.is_favorite');
        $this->getJson('/api/v1/favorites/status?station_ids[]='.$station->id)->assertJsonPath('favorite_station_ids.0', $station->id);
        // Laravel's in-process request guard cache must not substitute for
        // authentication of the next independent bearer HTTP request.
        Auth::forgetGuards();
        $this->withToken($other->createToken('other-favorite-test')->plainTextToken)->getJson('/api/v1/favorites')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stations')->assertJsonMissingPath('data.0.is_favorite');
        $this->getJson('/api/v1/favorites/status?station_ids[]='.$station->id)->assertJsonCount(0, 'favorite_station_ids');
    }
}
