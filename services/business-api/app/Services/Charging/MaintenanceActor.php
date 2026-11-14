<?php

namespace App\Services\Charging;

use App\Models\User;
use Illuminate\Support\Facades\DB;

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
}
