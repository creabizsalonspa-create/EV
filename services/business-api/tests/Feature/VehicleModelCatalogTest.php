<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VehicleModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function input(): array
    {
        return [
            'make' => 'Example Motors', 'model' => 'City E',
            'variant' => 'Long range', 'model_year' => 2026,
            'connector_type' => 'CCS2',
            'source_url' => 'https://example.com/vehicles/city-e',
            'published' => true,
        ];
    }

    private function vehicle(User $owner, string $connector = 'CCS2'): Vehicle
    {
        return Vehicle::create([
            'user_id' => $owner->id, 'name' => 'Family EV',
            'connector_type' => $connector,
        ]);
    }

    public function test_admin_can_publish_catalog_and_customers_can_link_existing_vehicle(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $driver = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $this->getJson('/api/vehicle-models')->assertUnauthorized();
        $this->actingAs($driver)->postJson('/api/vehicle-models', $this->input())->assertForbidden();
        $record = $this->actingAs($admin)->postJson('/api/vehicle-models', $this->input())
            ->assertCreated()->assertJsonPath('revision', 1)
            ->assertJsonPath('provenance', 'ADMIN_CURATED_UNVERIFIED')->json();
        $this->actingAs($driver)->getJson('/api/vehicle-models')
            ->assertOk()->assertJsonPath('data.0.make', 'Example Motors')
            ->assertJsonPath('data.0.id', $record['id']);

        $route = '/api/vehicles/'.$vehicle->id.'/model';
        $this->actingAs($driver)->putJson($route, [
            'model_id' => $record['id'], 'expected_model_id' => null,
        ])->assertOk()->assertJsonPath('model_id', $record['id'])
            ->assertJsonPath('does_not_override_connector', true);

        $this->actingAs($driver)->getJson($route)->assertOk()
            ->assertJsonPath('model.connector_type', 'CCS2');
        $this->assertSame('CCS2', $vehicle->fresh()->connector_type);
        $this->assertSame((int) $record['id'], (int) $vehicle->fresh()->vehicle_model_id);
    }

    public function test_catalog_archive_hides_public_choices_and_preserves_existing_vehicle_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $driver = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $record = $this->actingAs($admin)->postJson('/api/vehicle-models', $this->input())->json();
        $this->actingAs($driver)->putJson('/api/vehicles/'.$vehicle->id.'/model', [
            'model_id' => $record['id'], 'expected_model_id' => null,
        ])->assertOk();
        $archived = $this->input();
        $archived['revision'] = 1;
        $archived['published'] = false;
        $this->actingAs($admin)->putJson('/api/vehicle-models/'.$record['id'], $archived)
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('published', false);
        $this->actingAs($driver)->getJson('/api/vehicle-models')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($driver)->getJson('/api/vehicle-models?include_archived=1')
            ->assertForbidden();
        $this->actingAs($admin)->getJson('/api/vehicle-models?include_archived=1')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($driver)->getJson('/api/vehicles/'.$vehicle->id.'/model')
            ->assertOk()->assertJsonPath('model.published', false);
        $this->actingAs($driver)->putJson('/api/vehicles/'.$vehicle->id.'/model', [
            'model_id' => $record['id'], 'expected_model_id' => $record['id'],
        ])->assertUnprocessable();
        $this->actingAs($driver)->putJson('/api/vehicles/'.$vehicle->id.'/model', [
            'model_id' => null, 'expected_model_id' => $record['id'],
        ])->assertOk()->assertJsonPath('model_id', null);
    }

    public function test_concurrent_edits_duplicates_and_linked_connector_changes_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $driver = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $model = $this->actingAs($admin)->postJson('/api/vehicle-models', $this->input())->json();
        $this->actingAs($admin)->postJson('/api/vehicle-models', [
            ...$this->input(), 'make' => ' EXAMPLE Motors ',
        ])->assertConflict();

        $this->actingAs($driver)->putJson('/api/vehicles/'.$vehicle->id.'/model', [
            'model_id' => $model['id'], 'expected_model_id' => null,
        ])->assertOk();
        $this->actingAs($driver)->putJson('/api/vehicles/'.$vehicle->id.'/model', [
            'model_id' => null, 'expected_model_id' => null,
        ])->assertConflict();

        $edited = $this->input();
        $edited['revision'] = 1;
        $edited['connector_type'] = 'Type2';
        $this->actingAs($admin)->putJson('/api/vehicle-models/'.$model['id'], $edited)
            ->assertConflict();
        $edited['connector_type'] = 'CCS2';
        $this->actingAs($admin)->putJson('/api/vehicle-models/'.$model['id'], $edited)
            ->assertOk()->assertJsonPath('revision', 2);
        $this->actingAs($admin)->putJson('/api/vehicle-models/'.$model['id'], $edited)
            ->assertConflict();
        $this->assertSame(1, DB::table('vehicle_model_catalog')->count());
    }

    public function test_no_cross_account_access_or_unsafe_vehicle_connector_association(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vehicle = $this->vehicle($owner, 'Type2');
        $model = $this->actingAs($admin)->postJson('/api/vehicle-models', $this->input())->json();
        $route = '/api/vehicles/'.$vehicle->id.'/model';
        $this->actingAs($other)->getJson($route)->assertNotFound();
        $this->actingAs($other)->putJson($route, [
            'model_id' => $model['id'], 'expected_model_id' => null,
        ])->assertNotFound();
        $this->actingAs($vendor)->getJson($route)->assertNotFound();
        $this->actingAs($owner)->putJson($route, [
            'model_id' => $model['id'], 'expected_model_id' => null,
        ])->assertUnprocessable();
        $this->assertNull($vehicle->fresh()->vehicle_model_id);
    }

    public function test_sanctum_mobile_get_and_update_share_model_validation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $driver = User::factory()->create(['role' => 'customer']);
        $vehicle = $this->vehicle($driver);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/vehicle-models', $this->input())
            ->assertCreated()->assertJsonPath('model', 'City E');
        $id = DB::table('vehicle_model_catalog')->value('id');
        $this->actingAs($driver, 'sanctum')->getJson('/api/v1/vehicle-models')
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($driver, 'sanctum')->putJson('/api/v1/vehicles/'.$vehicle->id.'/model', [
            'model_id' => $id, 'expected_model_id' => null,
        ])->assertOk()->assertJsonPath('model.id', $id);
    }
}
