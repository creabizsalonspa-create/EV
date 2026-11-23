<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\FleetVehicle;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminFleetDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_current_admin_can_read_private_fleet_owners_with_allowlisted_inventory(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'customer', 'name' => '<img src=x> owner']);
        $driver = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'vendor']);
        $fleet = Fleet::create([
            'owner_id' => $owner->id, 'name' => 'Fleet 100%_!', 'country' => 'AE',
        ]);
        Fleet::create(['owner_id' => $other->id, 'name' => 'Second fleet', 'country' => 'CA']);
        FleetMember::create([
            'fleet_id' => $fleet->id,
            'user_id' => $driver->id, 'status' => 'active', 'role' => 'driver',
        ]);
        FleetMember::create([
            'fleet_id' => $fleet->id,
            'user_id' => $other->id, 'status' => 'invited', 'role' => 'driver',
        ]);
        $center = CostCenter::create(['fleet_id' => $fleet->id, 'code' => 'OPS', 'name' => 'Operations']);
        $vehicle = Vehicle::create([
            'user_id' => $owner->id, 'name' => 'Assigned EV', 'connector_type' => 'CCS2',
        ]);
        FleetVehicle::create([
            'fleet_id' => $fleet->id,
            'vehicle_id' => $vehicle->id,
            'cost_center_id' => $center->id,
        ]);

        $this->getJson('/api/admin/fleets')->assertUnauthorized();
        $this->actingAs($owner)->getJson('/api/admin/fleets')->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);

        $legacy = $this->actingAs($admin)->getJson('/api/admin/fleets')->assertOk();
        $this->assertSame(['id', 'name', 'country', 'created_at'], array_keys($legacy->json('data.0')));

        $response = $this->getJson('/api/admin/fleets?include=owner_inventory')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $fleet->id)
            ->assertJsonPath('data.0.owner.id', $owner->id)
            ->assertJsonPath('data.0.owner.name', '<img src=x> owner')
            ->assertJsonPath('data.0.owner.email', $owner->email)
            ->assertJsonPath('data.0.inventory.active_members', 1)
            ->assertJsonPath('data.0.inventory.assigned_vehicles', 1)
            ->assertJsonPath('data.0.inventory.cost_centers', 1);
        $response->assertJsonMissingPath('data.0.owner.password')
            ->assertJsonMissingPath('data.0.owner.remember_token')
            ->assertJsonMissingPath('data.0.owner.auth_epoch')
            ->assertJsonMissingPath('data.0.owner.email_verified_at')
            ->assertJsonMissingPath('data.0.owner.role');
        $this->assertStringNotContainsString($owner->password, $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.fleets_viewed',
            'subject' => 'all',
        ]);
    }

    public function test_admin_fleet_owner_filters_and_literal_name_search_apply_before_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $firstOwner = User::factory()->create(['role' => 'customer']);
        $secondOwner = User::factory()->create(['role' => 'customer']);
        Fleet::create(['owner_id' => $firstOwner->id, 'name' => 'Fleet 100%_!', 'country' => 'AE']);
        Fleet::create(['owner_id' => $secondOwner->id, 'name' => 'Fleet 100X marker', 'country' => 'CA']);

        $this->actingAs($admin);
        foreach (['%', '_', '!'] as $symbol) {
            $this->getJson('/api/admin/fleets?include=owner_inventory&q='.rawurlencode($symbol))
                ->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.owner.id', $firstOwner->id);
        }
        $this->getJson('/api/admin/fleets?include=owner_inventory&country=AE&owner_id='.$secondOwner->id)
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/fleets?include=owner_inventory&owner_id='.$firstOwner->id)
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.owner.id', $firstOwner->id);

        for ($i = 0; $i < 27; $i++) {
            Fleet::create([
                'owner_id' => $secondOwner->id,
                'name' => 'Fleet QA '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'country' => 'CA',
            ]);
        }
        $url = '/api/admin/fleets?include=owner_inventory&owner_id='.$secondOwner->id.'&country=CA&q=QA';
        $this->getJson($url.'&page=1')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonCount(25, 'data');
        $this->getJson($url.'&page=2')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.fleets_viewed',
            'subject' => 'owner:'.$secondOwner->id,
        ]);
    }

    public function test_private_fleet_finance_reports_authorize_before_filters_and_never_redirect_bad_csv(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $outsider = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $fleet = Fleet::create([
            'owner_id' => $owner->id,
            'name' => 'Synthetic enterprise operations',
            'country' => 'AE',
        ]);
        $url = '/api/fleets/'.$fleet->id.'/report';
        $valid = '?from=2026-11-01&through=2026-11-15';
        $invalid = '?from=invalid&through=2026-11-15&format=csv';
        $before = DB::table('finance_journals')->count();

        $this->actingAs($outsider)->getJson($url.$valid)->assertNotFound();
        $this->getJson($url.$invalid)->assertNotFound();
        $this->get($url.$invalid)->assertNotFound();
        $this->get($url.$valid.'&format=csv')->assertNotFound();

        $this->actingAs($owner);
        $this->getJson($url.$valid)->assertOk()->assertJsonPath(
            'fleet.id', $fleet->id
        )->assertJsonPath('physical_charging_verified', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url.$invalid)->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('message', 'Invalid private fleet report filters.');
        $this->get($url.$valid.'&format=csv')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->actingAs($admin)->getJson($url.$valid)->assertOk();
        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'customer',
        ]);
        $this->getJson($url.$invalid)->assertConflict();
        $this->get($url.$invalid)->assertConflict();
        $this->getJson('/api/admin/fleets?owner_id=0')->assertConflict();
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_fleet_owner_directory_rejects_bad_filters_and_revoked_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');
        foreach (['owner_id=0', 'owner_id=abc', 'country=usa', 'page=0', 'include=all'] as $filter) {
            $this->getJson('/api/admin/fleets?'.$filter)->assertUnprocessable();
        }
        $this->assertDatabaseCount('audit_events', 0);

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->getJson('/api/admin/fleets')->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin', 'auth_epoch' => 1,
        ]);
        $this->getJson('/api/admin/fleets')->assertStatus(409);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
