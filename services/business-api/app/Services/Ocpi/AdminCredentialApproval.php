<?php

namespace App\Services\Ocpi;

use App\Models\User;

/** Fence the authenticated approval again before claiming or sending credentials. */
final class AdminCredentialApproval
{
    public function current(User $snapshot, bool $lock = false): User
    {
        $user = User::whereKey($snapshot->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($snapshot->role === 'admin' && $snapshot->account_status === 'active' && $user
            && $user->role === 'admin' && $user->account_status === 'active', 403);
        abort_unless((int) $user->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $user->password, (string) $snapshot->password), 409, 'Administrative approval changed. Sign in and review the partner operation again.');

        return $user;
    }
}
