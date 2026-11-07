<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading pending commands must never leak a different vendor's charging
 * commands or enable a customer to see another tenant's OCPP references.
 * No CSMS transport or real hardware is used in these tests.
 */
class ChargerCommandTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function station(User $vendor, string $name): Station
    {
        return Station::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'country' => 'AE',
            'currency' => 'AED',
            'timezone' => 'Asia/Dubai',
            'latitude' => 25,
            'longitude' => 55,
            'source' => 'simulator',
            'connector_type' => 'CCS2',
            'power_kw' => 50,
        ]);
    }

    private function command(User $vendor, Station $station, string $key): ChargerCommand
    {
        return ChargerCommand::create([
            'actor_id' => $vendor->id,
            'station_id' => $station->id,
            'action' => 'start',
            'payload' => [],
            'payload_hash' => hash('sha256', $station->id.':'.$key),
            'idempotency_key' => $key,
        ]);
    }

    public function test_foreign_vendor_and_customer_cannot_read_queued_commands(): void
    {
        Http::preventStrayRequests();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $mine = $this->command($vendor, $this->station($vendor, 'QA own station'), 'qa-own');
        $foreign = $this->command($other, $this->station($other, 'QA other station'), 'qa-foreign');

        $mineResponse = $this->actingAs($vendor)
            ->getJson('/api/charger-commands?actor_id='.$other->id.'&station_id='.$foreign->station_id.'&include_all=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->assertNotContains($foreign->id, collect($mineResponse->json('data'))->pluck('id')->all());

        $otherResponse = $this->actingAs($other)
            ->getJson('/api/charger-commands?actor_id='.$vendor->id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $foreign->id);

        $this->assertNotContains($mine->id, collect($otherResponse->json('data'))->pluck('id')->all());

        $this->actingAs($customer)->getJson('/api/charger-commands?include_all=1')
            ->assertOk()->assertJsonCount(0, 'data');

        Http::assertNothingSent();
    }

    public function test_anonymous_command_list_is_denied_even_with_guessed_station_id(): void
    {
        $this->getJson('/api/charger-commands?station_id=2147483647')
            ->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
    }
}
