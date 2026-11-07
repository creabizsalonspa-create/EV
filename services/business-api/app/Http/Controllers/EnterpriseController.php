<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\FleetVehicle;
use App\Models\InboxNotification;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnterpriseController extends Controller
{
    private function owner(Request $r, Fleet $f): void
    {
        abort_unless($f->owner_id === $r->user()->id, 404);
    }

    private function member(Request $r, Fleet $f): void
    {
        abort_unless($f->owner_id === $r->user()->id || FleetMember::where('fleet_id', $f->id)->where('user_id', $r->user()->id)->where('status', 'active')->exists(), 404);
    }

    private function audit(Request $r, string $action, string $subject): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => $subject, 'created_at' => now()]);
    }

    public function regions()
    {
        return DB::table('regions')->orderBy('country')->get();
    }

    public function configureRegion(Request $r, string $country)
    {
        abort_unless($r->user()->role === 'admin', 403);
        abort_unless(preg_match('/^[A-Z]{2}$/', $country), 422);
        $v = $r->validate(['currency' => 'required|string|size:3|uppercase', 'minor_units' => 'required|integer|between:0,3', 'timezone' => 'required|timezone', 'tax_bps' => 'nullable|integer|between:0,10000']);

        return DB::transaction(function () use ($r, $country, $v) {
            DB::table('regions')->updateOrInsert(['country' => $country], $v + ['commerce_enabled' => false, 'updated_at' => now()]);
            $this->audit($r, 'region.configured', $country);

            return response()->json(DB::table('regions')->where('country', $country)->first());
        });
    }

    public function index(Request $r)
    {
        return Fleet::where('owner_id', $r->user()->id)->orWhereIn('id', FleetMember::where('user_id', $r->user()->id)->where('status', 'active')->select('fleet_id'))->paginate(25);
    }

    public function create(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:150', 'country' => ['required', Rule::exists('regions', 'country')]]);

        return DB::transaction(function () use ($r, $v) {
            $f = Fleet::create($v + ['owner_id' => $r->user()->id]);
            $this->audit($r, 'fleet.created', (string) $f->id);

            return response()->json($f, 201);
        });
    }

    public function show(Request $r, Fleet $fleet)
    {
        $this->member($r, $fleet);
        $owner = $fleet->owner_id === $r->user()->id;

        return response()->json(['fleet' => $fleet, 'cost_centers' => CostCenter::where('fleet_id', $fleet->id)->get(), 'members' => $owner ? FleetMember::where('fleet_id', $fleet->id)->get() : [], 'vehicles' => FleetVehicle::where('fleet_id', $fleet->id)->when(! $owner, fn ($q) => $q->whereIn('vehicle_id', Vehicle::where('user_id', $r->user()->id)->select('id')))->get()]);
    }

    public function invite(Request $r, Fleet $fleet)
    {
        $this->owner($r, $fleet);
        $v = $r->validate(['email' => 'required|email']);
        $u = User::where('email', $v['email'])->first();
        // Same response whether the address is registered; no account-enumeration signal.
        if ($u && $u->id !== $r->user()->id) {
            DB::transaction(function () use ($r, $fleet, $u) {
                $current = Fleet::whereKey($fleet->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->owner_id === $r->user()->id, 404);
                $member = FleetMember::where('fleet_id', $current->id)
                    ->where('user_id', $u->id)->lockForUpdate()->first();
                if ($member && $member->status !== 'revoked') {
                    // Invited or active: no new invitation and no repeat notice.
                    return;
                }
                if ($member) {
                    $member->update(['status' => 'invited']);
                } else {
                    $member = FleetMember::create([
                        'fleet_id' => $current->id,
                        'user_id' => $u->id,
                        'role' => 'driver',
                        'status' => 'invited',
                    ]);
                }
                InboxNotification::create([
                    'user_id' => $u->id,
                    'kind' => 'fleet.invitation',
                    'metadata' => ['fleet_id' => $current->id, 'membership_id' => $member->id],
                ]);
                $this->audit($r, 'fleet.invited', (string) $current->id);
            }, 3);
        }

        return response()->json(['message' => 'Invitation recorded if the account is eligible.'], 202);
    }

    public function invitations(Request $r)
    {
        return FleetMember::where('user_id', $r->user()->id)->where('status', 'invited')->paginate(25);
    }

    public function accept(Request $r, FleetMember $invitation)
    {
        abort_unless($invitation->user_id === $r->user()->id, 404);
        abort_unless(in_array($invitation->status, ['invited', 'active']), 409);

        return DB::transaction(function () use ($r, $invitation) {
            // Every membership state transition follows the same Fleet ->
            // membership row-lock order (invite, accept, revoke). This prevents
            // circular waits during concurrent acceptance/revocation.
            $fleet = Fleet::whereKey($invitation->fleet_id)->lockForUpdate()->firstOrFail();
            $invitation = FleetMember::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_unless($invitation->fleet_id === $fleet->id &&
                $invitation->user_id === $r->user()->id, 404);
            abort_unless(in_array($invitation->status, ['invited', 'active'], true), 409);
            if ($invitation->status === 'invited') {
                $invitation->update(['status' => 'active']);
                InboxNotification::create([
                    'user_id' => $fleet->owner_id,
                    'kind' => 'fleet.invitation.accepted',
                    'metadata' => ['fleet_id' => $fleet->id, 'membership_id' => $invitation->id],
                ]);
                $this->audit($r, 'fleet.accepted', (string) $invitation->id);
            }

            return response()->json($invitation);
        }, 3);
    }

    public function remove(Request $r, Fleet $fleet, FleetMember $membership)
    {
        $this->owner($r, $fleet);
        abort_unless($membership->fleet_id === $fleet->id, 404);

        return DB::transaction(function () use ($r, $membership, $fleet) {
            // Use the same Fleet -> membership lock order as invitation.
            $current = Fleet::whereKey($fleet->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->owner_id === $r->user()->id, 404);
            $membership = FleetMember::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            abort_unless($membership->fleet_id === $current->id, 404);
            if ($membership->status !== 'revoked') {
                // An unaccepted invitation is withdrawn, not a revoked active
                // membership. Only active members can have fleet vehicles.
                $wasInvited = $membership->status === 'invited';
                $membership->update(['status' => 'revoked']);
                if (! $wasInvited) {
                    FleetVehicle::where('fleet_id', $fleet->id)
                        ->whereIn('vehicle_id', Vehicle::where('user_id', $membership->user_id)->select('id'))->delete();
                }
                InboxNotification::create([
                    'user_id' => $membership->user_id,
                    'kind' => $wasInvited ? 'fleet.invitation.withdrawn' : 'fleet.membership.revoked',
                    'metadata' => ['fleet_id' => $fleet->id, 'membership_id' => $membership->id],
                ]);
                $this->audit(
                    $r,
                    $wasInvited ? 'fleet.invitation_withdrawn' : 'fleet.revoked',
                    (string) $membership->id
                );
            }

            return response()->noContent();
        }, 3);
    }

    public function costCenter(Request $r, Fleet $fleet)
    {
        $this->owner($r, $fleet);
        $v = $r->validate(['code' => ['required', 'alpha_dash', 'max:30', Rule::unique('cost_centers')->where('fleet_id', $fleet->id)], 'name' => 'required|string|max:100']);

        return response()->json(CostCenter::create($v + ['fleet_id' => $fleet->id]), 201);
    }

    public function assign(Request $r, Fleet $fleet)
    {
        $this->member($r, $fleet);
        $v = $r->validate(['vehicle_id' => 'required|integer', 'cost_center_id' => 'required|integer']);

        return DB::transaction(function () use ($r, $fleet, $v) {
            // Lock fleet before membership, as in invitation/accept/revoke.
            // A revocation cannot overtake an in-flight vehicle assignment.
            $current = Fleet::whereKey($fleet->id)->lockForUpdate()->firstOrFail();
            if ($current->owner_id !== $r->user()->id) {
                $membership = FleetMember::where('fleet_id', $current->id)
                    ->where('user_id', $r->user()->id)->lockForUpdate()->first();
                abort_unless($membership && $membership->status === 'active', 404);
            }
            $vehicle = Vehicle::whereKey($v['vehicle_id'])->lockForUpdate()->firstOrFail();
            abort_unless($vehicle->user_id === $r->user()->id, 404);
            abort_unless(CostCenter::whereKey($v['cost_center_id'])
                ->where('fleet_id', $current->id)->exists(), 404);

            $existing = FleetVehicle::where('vehicle_id', $vehicle->id)->first();
            if ($existing) {
                abort_unless($existing->fleet_id === $current->id, 409, 'Vehicle belongs to a different fleet.');
                if ((int) $existing->cost_center_id !== (int) $v['cost_center_id']) {
                    $existing->update(['cost_center_id' => $v['cost_center_id']]);
                    $this->audit($r, 'fleet.vehicle_cost_center_changed', (string) $existing->id);
                    if ($current->owner_id !== $r->user()->id) {
                        InboxNotification::create([
                            'user_id' => $current->owner_id,
                            'kind' => 'fleet.vehicle.cost_center_changed',
                            'metadata' => [
                                'fleet_id' => $current->id,
                                'vehicle_id' => $vehicle->id,
                                'assignment_id' => $existing->id,
                            ],
                        ]);
                    }
                }

                return response()->json($existing);
            }

            $assignment = FleetVehicle::create($v + ['fleet_id' => $current->id]);
            $this->audit($r, 'fleet.vehicle_assigned', (string) $assignment->id);
            if ($current->owner_id !== $r->user()->id) {
                InboxNotification::create([
                    'user_id' => $current->owner_id,
                    'kind' => 'fleet.vehicle.assigned',
                    'metadata' => [
                        'fleet_id' => $current->id,
                        'vehicle_id' => $vehicle->id,
                        'assignment_id' => $assignment->id,
                    ],
                ]);
            }

            return response()->json($assignment, 201);
        }, 3);
    }
}
