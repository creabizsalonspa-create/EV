<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Serialize self-service privacy disclosure and decisions with account revocation. */
final class PrivacyOwnerAuthority
{
    public static function requireCurrent(User $snapshot): User
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Privacy authority requires an active transaction.');
        }

        abort_unless($snapshot->account_status === 'active', 403);
        $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->account_status === 'active' && $current->role === $snapshot->role, 403);
        abort_unless((int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password),
            409, 'Account credentials changed. Sign in again.');

        return $current;
    }
}
