<?php

namespace App\Services\Charging;

use App\Models\User;

/** Request admission and dispatch share a user-row security epoch boundary. */
final class ChargingActorConsent
{
    public function capture(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->account_status === 'active' && $current->role === $actor->role
            && (int) $current->auth_epoch === (int) $actor->auth_epoch
            && hash_equals((string) $current->password, (string) $actor->password), 409, 'Account authorization changed. Sign in and confirm a new charging request.');

        return $current;
    }

    public function matches(?User $actor, ?int $epoch): bool
    {
        return $actor !== null && $epoch !== null && $actor->account_status === 'active'
            && in_array($actor->role, ['customer', 'vendor', 'admin'], true)
            && (int) $actor->auth_epoch === $epoch;
    }
}
