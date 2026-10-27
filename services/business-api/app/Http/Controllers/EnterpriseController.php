<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\FleetVehicle;
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
                FleetMember::firstOrCreate(['fleet_id' => $fleet->id, 'user_id' => $u->id], ['role' => 'driver', 'status' => 'invited']);
                $this->audit($r, 'fleet.invited', (string) $fleet->id);
            });
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
            $invitation = FleetMember::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($invitation->status, ['invited', 'active']), 409);
            $invitation->update(['status' => 'active']);
            $this->audit($r, 'fleet.accepted', (string) $invitation->id);

            return response()->json($invitation);
        });
    }

    public function remove(Request $r, Fleet $fleet, FleetMember $membership)
    {
        $this->owner($r, $fleet);
        abort_unless($membership->fleet_id === $fleet->id, 404);

        return DB::transaction(function () use ($r, $membership, $fleet) {
            $membership = FleetMember::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $membership->update(['status' => 'revoked']);
            FleetVehicle::where('fleet_id', $fleet->id)->whereIn('vehicle_id', Vehicle::where('user_id', $membership->user_id)->select('id'))->delete();
            $this->audit($r, 'fleet.revoked', (string) $membership->id);

            return response()->noContent();
        });
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
        $vehicle = Vehicle::whereKey($v['vehicle_id'])->where('user_id', $r->user()->id)->firstOrFail();
        abort_unless(CostCenter::whereKey($v['cost_center_id'])->where('fleet_id', $fleet->id)->exists(), 404);

        return DB::transaction(function () use ($r, $fleet, $v) {
            if ($fleet->owner_id !== $r->user()->id) {
                $membership = FleetMember::where('fleet_id', $fleet->id)->where('user_id', $r->user()->id)->lockForUpdate()->first();
                abort_unless($membership && $membership->status === 'active', 404);
            }
            Vehicle::whereKey($v['vehicle_id'])->lockForUpdate()->firstOrFail();
            $existing = FleetVehicle::where('vehicle_id', $v['vehicle_id'])->first();
            if ($existing) {
                abort_unless($existing->fleet_id === $fleet->id, 409, 'Vehicle belongs to a different fleet.');
                $existing->update(['cost_center_id' => $v['cost_center_id']]);

                return response()->json($existing);
            }$a = FleetVehicle::create($v + ['fleet_id' => $fleet->id]);
            $this->audit($r, 'fleet.vehicle_assigned', (string) $a->id);

            return response()->json($a, 201);
        });
    }
}
