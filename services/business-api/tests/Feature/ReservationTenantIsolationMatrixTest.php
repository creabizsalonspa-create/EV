<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Synthetic bookings only. No gateway connection or charger command.
 * Client-supplied owner/station selectors must not escape the actor's tenant.
 */
class ReservationTenantIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function booking(User $vendor, User $customer, string $name): Reservation
    {
        $station = Station::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'country' => 'AE',
            'currency' => 'AED',
            'timezone' => 'Asia/Dubai',
            'latitude' => 25.0,
            'longitude' => 55.0,
            'source' => 'simulator',
            'connector_type' => 'CCS2',
            'power_kw' => 50,
        ]);

        return Reservation::create([
            'station_id' => $station->id,
            'user_id' => $customer->id,
            'starts_at' => now()->addHour(),
            'status' => 'confirmed',
            'idempotency_key' => $name,
        ]);
    }

    public function test_web_customer_cannot_broaden_reservation_list_with_other_account_query(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $own = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $allowed = $this->booking($vendor, $own, 'owner-booking');
        $foreign = $this->booking($vendor, $other, 'foreign-booking');

        $response = $this->actingAs($own)->getJson('/api/reservations?user_id='.$other->id.'&station_id='.$foreign->station_id.'&include_all=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowed->id);

        $this->assertNotContains($foreign->id, collect($response->json('data'))->pluck('id')->all());
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_vendor_can_view_only_reservations_for_its_own_stations(): void
    {
        $ownerVendor = User::factory()->create(['role' => 'vendor']);
        $foreignVendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $allowed = $this->booking($ownerVendor, $customer, 'vendor-visible');
        $foreign = $this->booking($foreignVendor, $customer, 'foreign-vendor');

        $response = $this->actingAs($ownerVendor)->getJson('/api/reservations?vendor_id='.$foreignVendor->id.'&station_id='.$foreign->station_id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowed->id);

        $this->assertNotContains($foreign->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_native_bearer_cannot_broaden_another_customers_reservation_list(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $first = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $allowed = $this->booking($vendor, $first, 'native-owner-booking');
        $foreign = $this->booking($vendor, $other, 'native-foreign-booking');
        $bearer = $first->createToken('synthetic-reservation-qa')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($bearer)
            ->getJson('/api/v1/reservations?user_id='.$other->id.'&station_id='.$foreign->station_id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowed->id);

        $this->assertNotContains($foreign->id, collect($response->json('data'))->pluck('id')->all());
    }
}
