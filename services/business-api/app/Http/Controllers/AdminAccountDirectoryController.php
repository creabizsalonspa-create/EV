<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminAccountDirectoryController extends Controller
{
    /**
     * Bounded Admin account and business-review directory.
     *
     * Do not serialize full User or VendorCompany models: those contain
     * credentials, recovery data, KYC material and other private fields.
     */
    public function index(Request $request)
    {
        $this->requireActiveAdmin($request);

        $filters = $request->validate([
            'q' => 'sometimes|required|string|max:100',
            'role' => 'sometimes|required|in:customer,vendor,admin',
            'account_status' => 'sometimes|required|in:active,suspended',
            'verified' => 'sometimes|required|in:yes,no',
            'page' => 'sometimes|integer|min:1|max:100000',
        ]);

        $query = User::query()->select([
            'id', 'name', 'email', 'role', 'locale', 'email_verified_at', 'account_status', 'created_at',
        ]);

        if (isset($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        if (isset($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['account_status'])) {
            $query->where('account_status', $filters['account_status']);
        }

        if (isset($filters['verified'])) {
            if ($filters['verified'] === 'yes') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        $page = $query->orderByDesc('id')->paginate(25)->appends($filters);
        $ids = $page->getCollection()->pluck('id');
        $companies = VendorCompany::query()
            ->whereIn('owner_id', $ids)
            ->get(['id', 'owner_id', 'legal_name', 'country', 'status', 'reviewed_at'])
            ->groupBy('owner_id');

        return $page->through(function (User $user) use ($companies) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'account_status' => $user->account_status,
                'locale' => $user->locale,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at,
                'companies' => ($companies->get($user->id) ?? collect())->map(fn ($company) => [
                    'id' => $company->id,
                    'legal_name' => $company->legal_name,
                    'country' => $company->country,
                    'status' => $company->status,
                    'reviewed_at' => $company->reviewed_at,
                ])->values(),
            ];
        });
    }

    private function requireActiveAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User
            && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);

        return $actor;
    }

    /**
     * Admin-only, version-bound moderation decision. Never accepts account
     * role changes or exposes encrypted private review reasons in the catalog.
     */
    public function moderate(Request $request, User $target)
    {
        $actor = $this->requireActiveAdmin($request);
        $input = $request->validate([
            'expected_status' => 'required|in:active,suspended',
            'account_status' => 'required|in:active,suspended',
            'reason' => 'required|string|min:10|max:2000',
        ]);

        abort_if($actor->id === $target->id || $target->role === 'admin', 403);
        abort_if($input['account_status'] === $input['expected_status'], 422, 'Choose a different moderation state.');

        return DB::transaction(function () use ($actor, $target, $input) {
            $account = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            // Revalidate role/status under lock; stale decisions must not
            // overwrite another review or disable an administrator.
            abort_if($account->role === 'admin' || $actor->id === $account->id, 403);
            abort_unless($account->account_status === $input['expected_status'], 409, 'Account state changed. Refresh before reviewing.');

            $account->forceFill([
                'account_status' => $input['account_status'],
                // Every moderation transition revokes old browser session
                // grants, even when the session driver is not database.
                'auth_epoch' => (int) $account->auth_epoch + 1,
                'suspended_at' => $input['account_status'] === 'suspended' ? now() : null,
                'remember_token' => Str::random(60),
            ])->save();

            // Immediately end every known bearer token and database session.
            // AccountStatusGate also blocks any already-in-flight credential
            // after this transaction commits.
            $account->tokens()->delete();
            DB::table('sessions')->where('user_id', $account->id)->delete();
            DB::table('password_reset_tokens')->where('email', $account->email)->delete();

            DB::table('admin_account_moderations')->insert([
                'target_user_id' => $account->id,
                'actor_user_id' => $actor->id,
                'from_status' => $input['expected_status'],
                'to_status' => $input['account_status'],
                'reason_encrypted' => Crypt::encryptString($input['reason']),
                'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'admin.account.'.$input['account_status'],
                'subject' => (string) $account->id,
                'created_at' => now(),
            ]);

            return response()->json([
                'id' => $account->id,
                'account_status' => $account->account_status,
            ])->header('Cache-Control', 'no-store, private');
        });
    }

    public function moderationHistory(Request $request, User $target)
    {
        $this->requireActiveAdmin($request);

        return DB::table('admin_account_moderations')
            ->where('target_user_id', $target->id)
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn ($event) => [
                'id' => $event->id,
                'actor_user_id' => $event->actor_user_id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'reason' => Crypt::decryptString($event->reason_encrypted),
                'created_at' => $event->created_at,
            ]);
    }
}
