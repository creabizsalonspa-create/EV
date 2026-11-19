<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Recheck the current administrator authority inside an existing DB transaction.
 *
 * Callers must enter a transaction before invoking this guard. The row lock
 * orders a concurrent suspension, role change, password reset, or auth-epoch
 * revocation against the privileged database mutation that follows.
 */
final class AdminTransactionAuthority
{
    public static function requireCurrent(User $snapshot): User
    {
        abort_unless($snapshot->role === 'admin'
            && $snapshot->account_status === 'active', 403);

        // A FOR UPDATE query in autocommit cannot protect a later private
        // projection or mutation. Refuse caller misuse before any lookup.
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Administrator authority requires an active transaction.');
        }
        $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->role === 'admin'
            && $current->account_status === 'active', 403);

        abort_unless((int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password),
            409, 'Administrator credentials changed. Sign in again.');

        return $current;
    }
}
