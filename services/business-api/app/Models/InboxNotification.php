<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InboxNotification extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array'];

    protected $attributes = ['read_at' => null];

    /**
     * Both opening and closure escalation notices contain cross-company
     * operator identifiers; access
     * depends on the recipient's CURRENT role, not just who received them.
     * This single scope also constrains counters and read/unread mutations.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        // Request guards can retain an authenticated User instance after an
        // operator is demoted. Consult the authoritative role on each inbox
        // operation rather than trusting a stale session object.
        $currentlyAdmin = User::query()->whereKey($actor->id)
            ->where('role', 'admin')->exists();

        return $query->where('user_id', $actor->id)
            ->when(! $currentlyAdmin,
                fn (Builder $q) => $q->whereNotIn('kind', [
                    'charging.fault.escalated.simulated',
                    'charging.fault.escalation_closed.simulated',
                ]));
    }
}
