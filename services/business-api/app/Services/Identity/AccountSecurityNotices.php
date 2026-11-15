<?php

namespace App\Services\Identity;

use App\Models\InboxNotification;
use App\Models\User;

/** Written inside the same transaction as the proven account security change. */
final class AccountSecurityNotices
{
    public static function record(User $user, string $event): void
    {
        if (! in_array($event, ['enabled', 'disabled', 'recovery_rotated', 'recovery_used'], true)) {
            throw new \InvalidArgumentException('Unsupported account security event.');
        }
        InboxNotification::create(['user_id' => $user->id, 'kind' => 'account.security.'.$event,
            'metadata' => ['evidence' => 'ACCOUNT_SECURITY_EVENT']]);
    }
}
