<?php

namespace App\Services\Charging;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Preserve the captured login; a later account revision cannot approve its operation. */
final class RiderActor
{
    public function check(User $snapshot): void
    {
        $query = User::whereKey($snapshot->id);
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $current = $query->first();
        abort_unless($current && $current->account_status === 'active', 403);
        abort_unless($current->role === $snapshot->role && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password), 409, 'Account credentials changed. Sign in again.');
        app(MaintenanceActor::class)->checkBearer($snapshot);
    }
}
