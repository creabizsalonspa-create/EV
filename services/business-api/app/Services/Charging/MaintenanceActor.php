<?php

namespace App\Services\Charging;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Recheck the captured login; never replace it with a newer account's authority. */
final class MaintenanceActor
{
    public function check(User $snapshot): void
    {
        $query = User::whereKey($snapshot->id);
        // Keep the existing station/company/user lock order. Write callers
        // re-enter scope inside their dispatch/review transaction.
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $current = $query->first();
        abort_unless($current && $current->account_status === 'active', 403);
        abort_unless($current->role === $snapshot->role && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password), 409, 'Account credentials changed. Sign in again.');
    }

    /** Bearer logout can remove this token while leaving the account epoch unchanged. */
    public function checkBearer(User $snapshot): void
    {
        $token = $snapshot->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return;
        }
        $query = $token->newQuery()->whereKey($token->getKey());
        $current = DB::transactionLevel() > 0 ? $query->lockForUpdate()->first() : $query->first();
        $expiration = config('sanctum.expiration');
        abort_unless($current && (! $current->expires_at || $current->expires_at->isFuture())
            && (! $expiration || $current->created_at->gt(now()->subMinutes($expiration))), 403);
    }
}
