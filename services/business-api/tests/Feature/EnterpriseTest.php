<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\InboxNotification;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnterpriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_configure_regions_and_commerce_stays_disabled(): void
    {
        $this->getJson('/api/regions')->assertOk()->assertJsonPath('0.currency', 'AED')->assertJsonPath('0.commerce_enabled', fn ($value) => $value === false || $value === 0);
        $v = ['currency' => 'SAR', 'minor_units' => 2, 'timezone' => 'Asia/Riyadh', 'tax_bps' => 1500, 'commerce_enabled' => true];
        $this->actingAs(User::factory()->create())->putJson('/api/regions/SA', $v)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson('/api/regions/SA', $v)->assertOk()->assertJsonPath('commerce_enabled', fn ($value) => $value === false || $value === 0);
        $v['minor_units'] = 8;
        $this->putJson('/api/regions/SA', $v)->assertUnprocessable();
    }

    public function test_customer_fleet_pages_are_stable_private_and_complete(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $driver = User::factory()->create();

        $fleetIds = [];
        for ($i = 0; $i < 26; $i++) {
            $fleetIds[] = Fleet::create([
                'owner_id' => $owner->id,
                'name' => 'Owner fleet '.$i,
                'country' => 'AE',
            ])->id;
        }
        $foreignFleet = Fleet::create([
            'owner_id' => $other->id,
            'name' => 'Other account fleet',
            'country' => 'AE',
        ]);
        $active = FleetMember::create([
            'fleet_id' => $foreignFleet->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'role' => 'driver',
        ]);
        // A fleet shared with the actor is visible, but no unrelated fleets.
        $this->actingAs($owner)->getJson('/api/fleets?page=1')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.id', $foreignFleet->id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $page2 = $this->getJson('/api/fleets?page=2')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(2, 'data')
            ->json('data');
        $this->assertSame($fleetIds[0], $page2[1]['id']);
        $this->actingAs($driver)->getJson('/api/fleets?page=1')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($owner)->getJson('/api/fleets?page=0')
            ->assertUnprocessable();
        $this->actingAs($other)->deleteJson('/api/fleets/'.$foreignFleet->id.'/members/'.$active->id)
            ->assertNoContent();
        $this->actingAs($owner)->getJson('/api/fleets?page=1')
            ->assertOk()->assertJsonPath('total', 26)
            ->assertJsonPath('data.0.id', $fleetIds[25]);
    }

    public function test_stale_corporate_fleet_sessions_cannot_read_create_or_keep_former_membership(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $fleet = Fleet::create([
            'owner_id' => $owner->id, 'name' => 'Corporate private read',
            'country' => 'AE',
        ]);
        $membership = FleetMember::create([
            'fleet_id' => $fleet->id, 'user_id' => $driver->id,
            'status' => 'active', 'role' => 'driver',
        ]);
        $this->actingAs($owner);
        $this->getJson('/api/fleets')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/fleets/'.$fleet->id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/fleet-invitations')->assertOk();

        $snapshot = DB::table('users')->where('id', $owner->id)
            ->first(['account_status', 'role', 'auth_epoch', 'password']);
        $restore = (array) $snapshot;
        $before = DB::table('fleets')->count();
        foreach ([
            ['account_status' => 'suspended'],
            ['role' => 'vendor'],
            ['auth_epoch' => (int) $snapshot->auth_epoch + 1],
            ['password' => Hash::make('rotated-fleet-owner-password')],
        ] as $revocation) {
            DB::table('users')->where('id', $owner->id)->update($revocation);
            $this->getJson('/api/fleets')->assertConflict();
            $this->getJson('/api/fleets/'.$fleet->id)->assertConflict();
            $this->getJson('/api/fleet-invitations')->assertConflict();
            $this->postJson('/api/fleets', [
                'name' => 'Must not create from revoked session', 'country' => 'AE',
            ])->assertConflict();
            DB::table('users')->where('id', $owner->id)->update($restore);
        }
        $this->assertSame($before, DB::table('fleets')->count());

        $this->actingAs($driver)->getJson('/api/fleets/'.$fleet->id)
            ->assertOk()->assertJsonCount(0, 'members');
        DB::table('fleet_members')->where('id', $membership->id)
            ->update(['status' => 'revoked']);
        $this->getJson('/api/fleets/'.$fleet->id)->assertNotFound();
        $this->getJson('/api/fleets')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_customer_invitation_pagination_covers_more_than_twenty_five_without_cross_account_leaks(): void
    {
        $recipient = User::factory()->create();
        $other = User::factory()->create();
        $owner = User::factory()->create();
        $invitationIds = [];
        for ($i = 0; $i < 26; $i++) {
            $fleet = Fleet::create([
                'owner_id' => $owner->id,
                'name' => 'Invitation fleet '.$i,
                'country' => 'AE',
            ]);
            $invitationIds[] = FleetMember::create([
                'fleet_id' => $fleet->id,
                'user_id' => $recipient->id,
                'status' => 'invited',
                'role' => 'driver',
            ])->id;
        }
        $this->actingAs($recipient)->getJson('/api/fleet-invitations?page=1')
            ->assertOk()->assertJsonPath('total', 26)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.id', $invitationIds[25])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/fleet-invitations?page=2')
            ->assertOk()->assertJsonPath('current_page', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invitationIds[0]);
        $this->actingAs($other)->getJson('/api/fleet-invitations?page=1')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($recipient)->getJson('/api/fleet-invitations?page=-1')
            ->assertUnprocessable();
        $this->postJson('/api/fleet-invitations/'.$invitationIds[25].'/accept')
            ->assertOk();
        $this->getJson('/api/fleet-invitations?page=1')
            ->assertOk()->assertJsonPath('total', 25)
            ->assertJsonPath('data.0.id', $invitationIds[24]);
    }

    public function test_region_configuration_rechecks_live_admin_role_status_and_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');
        $payload = [
            'currency' => 'XZZ',
            'minor_units' => 2,
            'timezone' => 'Asia/Dubai',
            'tax_bps' => 1200,
            'commerce_enabled' => true,
        ];
        $country = 'XZ';

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->putJson('/api/regions/'.$country, $payload)->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin',
            'account_status' => 'suspended',
        ]);
        $this->putJson('/api/regions/'.$country, $payload)->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active',
            'auth_epoch' => 1,
        ]);
        $this->putJson('/api/regions/'.$country, $payload)->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0,
            'password' => Hash::make('rotated-owner-password'),
        ]);
        $this->putJson('/api/regions/'.$country, $payload)->assertStatus(409);
        $this->assertDatabaseMissing('regions', ['country' => $country]);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'region.configured',
            'subject' => $country,
        ]);

        $freshAdmin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($freshAdmin, 'web')
            ->putJson('/api/regions/'.$country, $payload)
            ->assertOk()->assertJsonPath('country', $country)
            ->assertJsonPath('currency', 'XZZ')
            ->assertJsonPath('commerce_enabled', fn ($enabled) => $enabled === false || $enabled === 0);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $freshAdmin->id,
            'action' => 'region.configured',
            'subject' => $country,
        ]);
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

    public function test_customer_can_assign_owned_vehicle_from_second_paginated_garage_page(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $fleet = Fleet::create([
            'owner_id' => $owner->id,
            'name' => 'Garage pagination fleet',
            'country' => 'AE',
        ]);
        $center = CostCenter::create([
            'fleet_id' => $fleet->id,
            'code' => 'LONG',
            'name' => 'Long garage',
        ]);
        $cars = [];
        for ($i = 0; $i < 26; $i++) {
            $cars[] = Vehicle::create([
                'user_id' => $owner->id,
                'name' => 'Private EV '.$i,
                'connector_type' => 'CCS2',
            ]);
        }
        $foreignCar = Vehicle::create([
            'user_id' => $other->id,
            'name' => 'Foreign EV',
            'connector_type' => 'CCS2',
        ]);

        $this->actingAs($owner)->getJson('/api/vehicles?page=1')
            ->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('total', 26);
        $this->getJson('/api/vehicles?page=2')
            ->assertOk()->assertJsonPath('current_page', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cars[25]->id);
        $this->postJson('/api/fleets/'.$fleet->id.'/vehicles', [
            'vehicle_id' => $cars[25]->id,
            'cost_center_id' => $center->id,
        ])->assertCreated();
        $this->getJson('/api/fleets/'.$fleet->id)
            ->assertOk()
            ->assertJsonPath('vehicles.0.vehicle_id', $cars[25]->id);
        $this->postJson('/api/fleets/'.$fleet->id.'/vehicles', [
            'vehicle_id' => $foreignCar->id,
            'cost_center_id' => $center->id,
        ])->assertNotFound();
        $this->actingAs($other)->getJson('/api/vehicles?page=2')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonCount(0, 'data');
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

        $this->assertSame(1, DB::table('audit_events')
            ->where('actor_id', $driver->id)->where('action', 'fleet.vehicle_assigned')->count());
        $this->assertSame(1, DB::table('audit_events')
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

    public function test_pending_invitation_withdrawal_is_distinct_from_active_revocation(): void
    {
        $owner = User::factory()->create();
        $driver = User::factory()->create();
        $fleet = Fleet::create([
            'owner_id' => $owner->id,
            'name' => 'Lifecycle type fixture',
            'country' => 'AE',
        ]);
        $member = FleetMember::create([
            'fleet_id' => $fleet->id,
            'user_id' => $driver->id,
            'role' => 'driver',
            'status' => 'invited',
        ]);
        $url = '/api/fleets/'.$fleet->id.'/members/'.$member->id;
        $this->actingAs($owner)->deleteJson($url)->assertNoContent();
        $this->deleteJson($url)->assertNoContent();
        $this->assertSame('revoked', $member->fresh()->status);
        $this->assertSame(1, InboxNotification::where('user_id', $driver->id)
            ->where('kind', 'fleet.invitation.withdrawn')->count());
        $this->assertSame(0, InboxNotification::where('user_id', $driver->id)
            ->where('kind', 'fleet.membership.revoked')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('actor_id', $owner->id)
            ->where('action', 'fleet.invitation_withdrawn')->count());

        $this->postJson('/api/fleets/'.$fleet->id.'/invitations', [
            'email' => $driver->email,
        ])->assertAccepted();
        $this->actingAs($driver)->postJson(
            '/api/fleet-invitations/'.$member->id.'/accept'
        )->assertOk();
        $this->actingAs($owner)->deleteJson($url)->assertNoContent();
        $this->assertSame(1, InboxNotification::where('user_id', $driver->id)
            ->where('kind', 'fleet.membership.revoked')->count());
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
