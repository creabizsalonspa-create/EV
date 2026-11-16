<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Pin the persisted account identity while materializing private financial
 * reads. Call only inside a database transaction: the row lock must cover
 * all tenant-scoped queries, not merely this initial authorization check.
 *
 * This is a read guard, not permission to capture, refund or move funds.
 */
final class CurrentFinanceReader
{
    public function requireCurrent(Request $request): User
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User, 401);
        $current = User::query()->whereKey($snapshot->id)->lockForUpdate()->first();

        abort_unless($current && $current->account_status === 'active'
            && $current->role === $snapshot->role
            && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password),
            409, 'Account authorization changed; sign in again.');

        return $current;
    }
}
