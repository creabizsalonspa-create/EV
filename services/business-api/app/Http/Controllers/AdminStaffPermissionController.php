<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AdminStaffPermissionController extends Controller
{
    private function activeAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User
            && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);

        return $actor;
    }

    /**
     * A bounded read-only view of the *existing* company staff grants.
     * The underlying VendorAccess permission checks remain authoritative.
     */
    public function index(Request $request)
    {
        $this->activeAdmin($request);
        $input = $request->validate([
            'company_id' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:active,invited,revoked',
            'page' => 'sometimes|integer|min:1|max:100000',
        ]);

        $query = VendorCompanyStaff::query()->select([
            'id', 'company_id', 'user_id', 'status', 'permissions',
            'invitation_version', 'accepted_at', 'admin_revoked_at',
        ]);
        if (isset($input['company_id'])) {
            $query->where('company_id', $input['company_id']);
        }
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        $page = $query->orderByDesc('id')->paginate(25);
        $rows = $page->getCollection();
        $companies = VendorCompany::query()
            ->whereIn('id', $rows->pluck('company_id'))
            ->get(['id', 'legal_name', 'country'])
            ->keyBy('id');
        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id'))
            ->get(['id', 'name', 'email', 'account_status'])
            ->keyBy('id');

        return response()->json($page->through(function (VendorCompanyStaff $staff) use ($companies, $users) {
            $company = $companies->get($staff->company_id);
            $user = $users->get($staff->user_id);
            return [
                'id' => $staff->id,
                'company_id' => $staff->company_id,
                'user_id' => $staff->user_id,
                'status' => $staff->status,
                'permissions' => array_values($staff->permissions ?? []),
                'invitation_version' => $staff->invitation_version,
                'accepted_at' => $staff->accepted_at,
                'admin_emergency_hold' => $staff->admin_revoked_at !== null,
                'company' => $company ? [
                    'id' => $company->id,
                    'name' => $company->legal_name,
                    'country' => $company->country,
                ] : null,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'account_status' => $user->account_status,
                ] : null,
            ];
        }))->header('Cache-Control', 'no-store, private');
    }

    /**
     * Emergency revocation by Admin. Does not create a competing RBAC ledger
     * or revoke the person's unrelated company/consumer credentials.
     */
    public function revoke(Request $request, VendorCompanyStaff $membership)
    {
        $actor = $this->activeAdmin($request);
        $input = $request->validate([
            'expected_version' => 'required|integer|min:1',
            'reason' => 'required|string|min:10|max:2000',
        ]);

        return DB::transaction(function () use ($actor, $membership, $input) {
            $locked = VendorCompanyStaff::whereKey($membership->id)
                ->lockForUpdate()->firstOrFail();
            // A company owner might revoke a membership just before the
            // administrator acts. Admin can still place a durable hold on
            // owner-revoked records, but cannot double-revoke an existing hold.
            abort_unless(in_array($locked->status, ['active', 'invited', 'revoked'], true)
                && $locked->admin_revoked_at === null,
                409, 'Membership is already under an administrator hold.');
            abort_unless($locked->invitation_version === (int) $input['expected_version'],
                409, 'Staff permissions changed. Refresh before revoking.');

            $previousVersion = $locked->invitation_version;
            $locked->status = 'revoked';
            $locked->admin_revoked_at = now();
            $locked->invitation_version = $previousVersion + 1;
            $locked->save();

            DB::table('admin_permission_revocations')->insert([
                'membership_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'replaced_version' => $previousVersion,
                'action' => 'revoked',
                'reason_encrypted' => Crypt::encryptString(trim($input['reason'])),
                'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'admin.permission.revoked',
                'subject' => (string) $locked->id,
                'created_at' => now(),
            ]);

            return response()->json([
                'id' => $locked->id,
                'status' => 'revoked',
                'invitation_version' => $locked->invitation_version,
                'admin_emergency_hold' => true,
            ])->header('Cache-Control', 'no-store, private');
        });
    }


    /**
     * Release an administrative hold but do NOT regrant access. The company
     * owner must send a new invite, and the staff user must accept it afresh.
     */
    public function release(Request $request, VendorCompanyStaff $membership)
    {
        $actor = $this->activeAdmin($request);
        $input = $request->validate([
            'expected_version' => 'required|integer|min:1',
            'reason' => 'required|string|min:10|max:2000',
        ]);

        return DB::transaction(function () use ($actor, $membership, $input) {
            $locked = VendorCompanyStaff::whereKey($membership->id)
                ->lockForUpdate()->firstOrFail();
            // A legacy writer may have incorrectly marked a held membership
            // active. Admin may resolve that hold, but must never turn the
            // release into an access grant.
            abort_unless($locked->admin_revoked_at !== null,
                409, 'No emergency hold to release.');
            abort_unless($locked->invitation_version === (int) $input['expected_version'],
                409, 'Staff permissions changed. Refresh before releasing.');

            $previousVersion = $locked->invitation_version;
            $locked->status = 'revoked';
            $locked->admin_revoked_at = null;
            $locked->invitation_version = $previousVersion + 1;
            $locked->save();

            DB::table('admin_permission_revocations')->insert([
                'membership_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'replaced_version' => $previousVersion,
                'action' => 'released',
                'reason_encrypted' => Crypt::encryptString(trim($input['reason'])),
                'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'admin.permission.hold_released',
                'subject' => (string) $locked->id,
                'created_at' => now(),
            ]);

            return response()->json([
                'id' => $locked->id,
                'status' => 'revoked',
                'invitation_version' => $locked->invitation_version,
                'admin_emergency_hold' => false,
                'requires_fresh_invitation' => true,
            ])->header('Cache-Control', 'no-store, private');
        });
    }

    public function revocations(Request $request, VendorCompanyStaff $membership)
    {
        $this->activeAdmin($request);
        $history = DB::table('admin_permission_revocations')
            ->where('membership_id', $membership->id)
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn ($entry) => [
                'id' => $entry->id,
                'actor_user_id' => $entry->actor_user_id,
                'replaced_version' => $entry->replaced_version,
                'action' => $entry->action,
                'reason' => Crypt::decryptString($entry->reason_encrypted),
                'created_at' => $entry->created_at,
            ]);

        DB::table('audit_events')->insert([
            'actor_id' => $request->user()->id,
            'action' => 'admin.private_history.staff_viewed',
            'subject' => (string) $membership->id,
            'created_at' => now(),
        ]);

        return response()->json($history)->header('Cache-Control', 'no-store, private');
    }
}
