<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Revalidate the persisted account on private inbox reads and receipt writes.
 * A suspended or rotated authenticated snapshot must never outlive its rights.
 */
final class CurrentNotificationActor
{
    public function revalidate(User $snapshot, bool $forMutation = false): User
    {
        $query = User::whereKey($snapshot->id);
        $current = $forMutation ? $query->lockForUpdate()->firstOrFail() : $query->firstOrFail();
        abort_unless($current->account_status === 'active'
            && $current->role === $snapshot->role
            && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password),
            409, 'Account credentials changed; sign in again.');

        return $current;
    }
}
