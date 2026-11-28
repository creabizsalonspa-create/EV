<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\FleetVehicle;
use App\Models\InboxNotification;
use App\Models\User;
use App\Models\Vehicle;
use App\Security\AdminTransactionAuthority;
use App\Security\CurrentFinanceReader;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        abort_unless(preg_match('/^[A-Z]{2}$/D', $country), 422);
        $v = $r->validate([
            'currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
            'minor_units' => 'required|integer|between:0,3',
            'timezone' => 'required|timezone',
            'tax_bps' => 'nullable|integer|between:0,10000',
            'expected_revision' => 'sometimes|integer|between:0,9007199254740990',
        ]);

        return DB::transaction(function () use ($r, $country, $v) {
            AdminTransactionAuthority::requireCurrent($r->user());
            $expected = (int) ($v['expected_revision'] ?? 0);
            $values = [
                'currency' => $v['currency'], 'minor_units' => (int) $v['minor_units'],
                'timezone' => $v['timezone'], 'tax_bps' => isset($v['tax_bps']) ? (int) $v['tax_bps'] : null,
                'commerce_enabled' => false,
            ];
            $row = DB::table('regions')->where('country', $country)->lockForUpdate()->first();
            // Keep existing configurations editable while requiring IANA
            // country data for any new or changed regional time zone.
            if ($row?->timezone !== $values['timezone']) {
                $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
                if (! in_array($values['timezone'], $zones, true)) {
                    throw ValidationException::withMessages([
                        'timezone' => 'Choose a time zone assigned to this country in the IANA time zone database.',
                    ]);
                }
            }
            if (! $row) {
                abort_unless($expected === 0, 409, 'Regional configuration changed. Refresh before saving.');
                // A concurrent first writer cannot be overwritten by another
                // administrator whose form expected an absent country.
                $inserted = DB::table('regions')->insertOrIgnore(['country' => $country]
                    + $values + ['configuration_revision' => 1, 'updated_at' => now()]);
                abort_unless($inserted === 1, 409, 'Regional configuration changed. Refresh before saving.');
            } else {
                $revision = (int) $row->configuration_revision;
                abort_unless($revision === $expected && $revision < 9007199254740991, 409, 'Regional configuration changed. Refresh before saving.');
                $same = $row->currency === $values['currency']
                    && (int) $row->minor_units === $values['minor_units']
                    && $row->timezone === $values['timezone']
                    && ($row->tax_bps === null ? null : (int) $row->tax_bps) === $values['tax_bps']
                    && ! $row->commerce_enabled;
                if ($same) {
                    return response()->json($row)->header('Cache-Control', 'private, no-store');
                }
                DB::table('regions')->where('country', $country)->update($values + [
                    'configuration_revision' => $revision + 1, 'updated_at' => now(),
                ]);
            }
            $this->audit($r, 'region.configured', $country);

            return response()->json(DB::table('regions')->where('country', $country)->first())
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function configureRegionalReviewIntake(Request $r, string $country)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $values = $r->validate(['enabled' => 'required|boolean', 'expected_revision' => 'required|integer|min:0']);

        return DB::transaction(function () use ($r, $country, $values) {
            AdminTransactionAuthority::requireCurrent($r->user());
            $row = DB::table('regions')->where('country', $country)->lockForUpdate()->firstOrFail();
            $enabled = (bool) $values['enabled'];
            $revision = (int) $row->review_intake_revision;
            abort_unless($revision === (int) $values['expected_revision'], 409, 'Regional review settings changed. Refresh before deciding.');
            if ($enabled !== (bool) $row->review_intake_enabled) {
                $revision++;
                DB::table('regions')->where('country', $country)->update([
                    'review_intake_enabled' => $enabled, 'review_intake_revision' => $revision, 'updated_at' => now(),
                ]);
                $this->audit($r, $enabled ? 'region.review_intake_enabled' : 'region.review_intake_disabled', $country.':'.$revision);
            }

            return response()->json(['country' => $country, 'review_intake_enabled' => $enabled, 'review_intake_revision' => $revision])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function index(Request $r)
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return DB::transaction(function () use ($r) {
            // A cached signed-in user is not sufficient to view private fleet
            // membership and vehicles after role, account or epoch revocation.
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json(Fleet::where(function ($query) use ($actor) {
                $query->where('owner_id', $actor->id)
                    ->orWhereIn('id', FleetMember::where('user_id', $actor->id)
                        ->where('status', 'active')->select('fleet_id'));
            })->orderByDesc('id')->paginate(25))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function create(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:150', 'country' => ['required', Rule::exists('regions', 'country')]]);

        return DB::transaction(function () use ($r, $v) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $f = Fleet::create($v + ['owner_id' => $actor->id]);
            $this->audit($r, 'fleet.created', (string) $f->id);

            return response()->json($f, 201);
        });
    }

    public function show(Request $r, Fleet $fleet)
    {
        return DB::transaction(function () use ($r, $fleet) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            // Bind the current persisted fleet identity, not a stale route
            // model whose owner or membership could have changed.
            $current = Fleet::whereKey($fleet->id)->lockForUpdate()->firstOrFail();
            $owner = (int) $current->owner_id === (int) $actor->id;
            abort_unless($owner || FleetMember::where('fleet_id', $current->id)
                ->where('user_id', $actor->id)->where('status', 'active')->exists(), 404);

            return response()->json([
                'fleet' => $current,
                'cost_centers' => CostCenter::where('fleet_id', $current->id)->get(),
                'members' => $owner ? FleetMember::where('fleet_id', $current->id)->get() : [],
                'vehicles' => FleetVehicle::where('fleet_id', $current->id)
                    ->when(! $owner, fn ($q) => $q->whereIn('vehicle_id',
                        Vehicle::where('user_id', $actor->id)->select('id')))->get(),
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
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
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);

            return response()->json(FleetMember::where('user_id', $actor->id)
                ->where('status', 'invited')->orderByDesc('id')->paginate(25))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
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
