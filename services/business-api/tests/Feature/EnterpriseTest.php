<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_configure_regions_and_commerce_stays_disabled(): void
    {
        $this->getJson('/api/regions')->assertOk()->assertJsonPath('0.currency', 'AED')->assertJsonPath('0.commerce_enabled', 0);
        $v = ['currency' => 'SAR', 'minor_units' => 2, 'timezone' => 'Asia/Riyadh', 'tax_bps' => 1500, 'commerce_enabled' => true];
        $this->actingAs(User::factory()->create())->putJson('/api/regions/SA', $v)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson('/api/regions/SA', $v)->assertOk()->assertJsonPath('commerce_enabled', 0);
        $v['minor_units'] = 8;
        $this->putJson('/api/regions/SA', $v)->assertUnprocessable();
    }

    public function test_invitation_requires_recipient_acceptance_and_tenant_privacy(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $outsider = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/fleets', ['name' => 'Test fleet', 'country' => 'AE'])->assertCreated();
        $f = Fleet::first();
        $this->postJson('/api/fleets/'.$f->id.'/invitations', ['email' => $driver->email])->assertAccepted();
        $m = FleetMember::first();
        $this->actingAs($driver)->getJson('/api/fleets/'.$f->id)->assertNotFound();
        $this->actingAs($outsider)->postJson('/api/fleet-invitations/'.$m->id.'/accept')->assertNotFound();
        $this->actingAs($driver)->getJson('/api/fleet-invitations')->assertJsonCount(1, 'data');
        $this->postJson('/api/fleet-invitations/'.$m->id.'/accept')->assertOk();
        $this->getJson('/api/fleets')->assertJsonCount(1, 'data');
        $this->getJson('/api/fleets/'.$f->id)->assertOk()->assertJsonCount(0, 'members');
        $this->postJson('/api/fleets/'.$f->id.'/invitations', ['email' => $outsider->email])->assertNotFound();
        $this->postJson('/api/fleets/'.$f->id.'/cost-centers', ['code' => 'OPS', 'name' => 'Operations'])->assertNotFound();
    }

    public function test_vehicle_assignment_requires_own_vehicle_and_same_fleet_cost_center_and_revocation_removes_access(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $other = User::factory()->create();
        $f = Fleet::create(['owner_id' => $owner->id, 'name' => 'Fleet A', 'country' => 'AE']);
        $g = Fleet::create(['owner_id' => $other->id, 'name' => 'Fleet B', 'country' => 'AE']);
        $m = FleetMember::create(['fleet_id' => $f->id, 'user_id' => $driver->id, 'status' => 'active', 'role' => 'driver']);
        $center = CostCenter::create(['fleet_id' => $f->id, 'code' => 'OPS', 'name' => 'Operations']);
        $wrong = CostCenter::create(['fleet_id' => $g->id, 'code' => 'X', 'name' => 'Other']);
        $car = Vehicle::create(['user_id' => $driver->id, 'name' => 'Fleet EV', 'connector_type' => 'CCS2']);
        $othercar = Vehicle::create(['user_id' => $other->id, 'name' => 'Other EV', 'connector_type' => 'Type2']);
        $this->actingAs($driver)->postJson('/api/fleets/'.$f->id.'/vehicles', ['vehicle_id' => $car->id, 'cost_center_id' => $wrong->id])->assertNotFound();
        $this->postJson('/api/fleets/'.$f->id.'/vehicles', ['vehicle_id' => $othercar->id, 'cost_center_id' => $center->id])->assertNotFound();
        $this->postJson('/api/fleets/'.$f->id.'/vehicles', ['vehicle_id' => $car->id, 'cost_center_id' => $center->id])->assertCreated();
        $this->postJson('/api/fleets/'.$f->id.'/vehicles', ['vehicle_id' => $car->id, 'cost_center_id' => $center->id])->assertOk();
        $this->actingAs($other)->deleteJson('/api/fleets/'.$f->id.'/members/'.$m->id)->assertNotFound();
        $this->actingAs($owner)->deleteJson('/api/fleets/'.$f->id.'/members/'.$m->id)->assertNoContent();
        $this->assertDatabaseCount('fleet_vehicles', 0);
        $this->actingAs($driver)->getJson('/api/fleets/'.$f->id)->assertNotFound();
        $this->postJson('/api/fleet-invitations/'.$m->id.'/accept')->assertConflict();
    }

    public function test_invites_do_not_disclose_whether_email_exists(): void
    {
        $owner = User::factory()->create();
        $f = Fleet::create(['owner_id' => $owner->id, 'name' => 'Fleet', 'country' => 'AE']);
        $this->actingAs($owner)->postJson('/api/fleets/'.$f->id.'/invitations', ['email' => 'unregistered@example.test'])->assertAccepted()->assertExactJson(['message' => 'Invitation recorded if the account is eligible.']);
    }
}
