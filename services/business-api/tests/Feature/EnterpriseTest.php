<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\InboxNotification;
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

    public function test_fleet_inbox_lifecycle_is_account_scoped_and_transition_idempotent(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $outsider = User::factory()->create();
        $fleet = Fleet::create(['owner_id' => $owner->id, 'name' => 'Notification fleet', 'country' => 'AE']);
        $url = '/api/fleets/'.$fleet->id.'/invitations';
        $body = ['email' => $driver->email];

        $this->actingAs($owner)->postJson($url, $body)->assertAccepted();
        $this->postJson($url, $body)->assertAccepted();
        $this->postJson($url, ['email' => 'absent@example.test'])
            ->assertAccepted()->assertExactJson(['message' => 'Invitation recorded if the account is eligible.']);
        $this->assertDatabaseCount('inbox_notifications', 1);
        $invite = FleetMember::where('fleet_id', $fleet->id)->where('user_id', $driver->id)->firstOrFail();
        $this->actingAs($driver)->getJson('/api/v1/notifications?category=fleet')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'fleet.invitation');
        $this->actingAs($outsider)->getJson('/api/v1/notifications?category=fleet')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($driver)->postJson('/api/fleet-invitations/'.$invite->id.'/accept')->assertOk();
        $this->postJson('/api/fleet-invitations/'.$invite->id.'/accept')->assertOk();
        $this->assertDatabaseCount('inbox_notifications', 2);
        $this->actingAs($owner)->getJson('/api/v1/notifications?category=fleet')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.kind', 'fleet.invitation.accepted');

        $this->deleteJson('/api/fleets/'.$fleet->id.'/members/'.$invite->id)->assertNoContent();
        $this->deleteJson('/api/fleets/'.$fleet->id.'/members/'.$invite->id)->assertNoContent();
        $this->assertDatabaseCount('inbox_notifications', 3);
        $this->actingAs($driver)->getJson('/api/v1/notifications?category=fleet')
            ->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.kind', 'fleet.membership.revoked');
        $this->actingAs($owner)->postJson($url, $body)->assertAccepted();
        $this->assertSame('invited', $invite->fresh()->status);
        $this->assertDatabaseCount('inbox_notifications', 4);
        $this->actingAs($driver)->getJson('/api/v1/notifications?category=fleet')
            ->assertOk()->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.kind', 'fleet.invitation');

        $this->assertSame(3, InboxNotification::where('user_id', $driver->id)->count());
        $this->assertSame(1, InboxNotification::where('user_id', $owner->id)->count());
    }

    public function test_driver_assignment_notifications_and_cost_center_change_audit_are_idempotent(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $thirdParty = User::factory()->create();
        $fleet = Fleet::create(['owner_id' => $owner->id, 'name' => 'Audit fixture', 'country' => 'AE']);
        FleetMember::create([
            'fleet_id' => $fleet->id, 'user_id' => $driver->id,
            'status' => 'active', 'role' => 'driver',
        ]);
        $first = CostCenter::create(['fleet_id' => $fleet->id, 'code' => 'A', 'name' => 'Alpha']);
        $second = CostCenter::create(['fleet_id' => $fleet->id, 'code' => 'B', 'name' => 'Beta']);
        $vehicle = Vehicle::create([
            'user_id' => $driver->id, 'name' => 'Driver EV', 'connector_type' => 'CCS2',
        ]);
        $endpoint = '/api/fleets/'.$fleet->id.'/vehicles';
        $input = ['vehicle_id' => $vehicle->id, 'cost_center_id' => $first->id];

        $this->actingAs($driver)->postJson($endpoint, $input)->assertCreated();
        $this->postJson($endpoint, $input)->assertOk();
        $this->assertSame(1, InboxNotification::where('user_id', $owner->id)->count());

        $input['cost_center_id'] = $second->id;
        $this->postJson($endpoint, $input)->assertOk();
        $this->postJson($endpoint, $input)->assertOk();
        $this->assertSame(2, InboxNotification::where('user_id', $owner->id)->count());

        $this->actingAs($owner)->getJson('/api/v1/notifications?category=fleet')
            ->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.kind', 'fleet.vehicle.cost_center_changed')
            ->assertJsonPath('data.1.kind', 'fleet.vehicle.assigned');
        $this->actingAs($thirdParty)->getJson('/api/v1/notifications?category=fleet')
            ->assertOk()->assertJsonPath('total', 0);

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('audit_events')
            ->where('actor_id', $driver->id)->where('action', 'fleet.vehicle_assigned')->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('audit_events')
            ->where('actor_id', $driver->id)->where('action', 'fleet.vehicle_cost_center_changed')->count());
    }

    public function test_revoked_driver_cannot_assign_vehicle_even_after_previous_membership_access(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $outsider = User::factory()->create();
        $fleet = Fleet::create(['owner_id' => $owner->id, 'name' => 'Locked assignment', 'country' => 'AE']);
        $center = CostCenter::create(['fleet_id' => $fleet->id, 'code' => 'OPS', 'name' => 'Operations']);
        $member = FleetMember::create([
            'fleet_id' => $fleet->id, 'user_id' => $driver->id,
            'role' => 'driver', 'status' => 'active',
        ]);
        $vehicle = Vehicle::create([
            'user_id' => $driver->id, 'name' => 'Driver car', 'connector_type' => 'CCS2',
        ]);
        $otherVehicle = Vehicle::create([
            'user_id' => $outsider->id, 'name' => 'Foreign car', 'connector_type' => 'CCS2',
        ]);
        $payload = ['vehicle_id' => $vehicle->id, 'cost_center_id' => $center->id];
        $path = '/api/fleets/'.$fleet->id.'/vehicles';

        $this->actingAs($driver)->postJson($path, [
            'vehicle_id' => $otherVehicle->id,
            'cost_center_id' => $center->id,
        ])->assertNotFound();
        $this->postJson($path, $payload)->assertCreated();
        $this->assertDatabaseCount('fleet_vehicles', 1);
        $this->actingAs($owner)->deleteJson(
            '/api/fleets/'.$fleet->id.'/members/'.$member->id
        )->assertNoContent();
        $this->assertDatabaseCount('fleet_vehicles', 0);
        $this->actingAs($driver)->postJson($path, $payload)->assertNotFound();
        $this->assertDatabaseCount('fleet_vehicles', 0);
        $this->assertDatabaseHas('fleet_members', [
            'id' => $member->id, 'status' => 'revoked',
        ]);
    }

    public function test_fleet_invitation_rejects_revoked_state_until_owner_explicitly_reinvites(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $outsider = User::factory()->create();
        $fleet = Fleet::create([
            'owner_id' => $owner->id,
            'name' => 'Concurrent transitions fixture',
            'country' => 'AE',
        ]);
        $membership = FleetMember::create([
            'fleet_id' => $fleet->id,
            'user_id' => $driver->id,
            'role' => 'driver',
            'status' => 'invited',
        ]);

        // The driver cannot trigger membership status updates for another user.
        $this->actingAs($outsider)
            ->postJson('/api/fleet-invitations/'.$membership->id.'/accept')
            ->assertNotFound();

        $this->actingAs($owner)
            ->deleteJson('/api/fleets/'.$fleet->id.'/members/'.$membership->id)
            ->assertNoContent();
        $this->assertDatabaseHas('fleet_members', [
            'id' => $membership->id,
            'status' => 'revoked',
        ]);

        $this->actingAs($driver)
            ->postJson('/api/fleet-invitations/'.$membership->id.'/accept')
            ->assertConflict();
        $this->assertSame(
            0,
            InboxNotification::where('user_id', $owner->id)
                ->where('kind', 'fleet.invitation.accepted')->count(),
        );

        $this->actingAs($owner)
            ->postJson('/api/fleets/'.$fleet->id.'/invitations', ['email' => $driver->email])
            ->assertAccepted();
        $this->actingAs($driver)
            ->postJson('/api/fleet-invitations/'.$membership->id.'/accept')
            ->assertOk();
        $this->postJson('/api/fleet-invitations/'.$membership->id.'/accept')
            ->assertOk();
        $this->assertSame(
            1,
            InboxNotification::where('user_id', $owner->id)
                ->where('kind', 'fleet.invitation.accepted')->count(),
        );
    }

    public function test_invites_do_not_disclose_whether_email_exists(): void
    {
        $owner = User::factory()->create();
        $f = Fleet::create(['owner_id' => $owner->id, 'name' => 'Fleet', 'country' => 'AE']);
        $this->actingAs($owner)->postJson('/api/fleets/'.$f->id.'/invitations', ['email' => 'unregistered@example.test'])->assertAccepted()->assertExactJson(['message' => 'Invitation recorded if the account is eligible.']);
    }
}
