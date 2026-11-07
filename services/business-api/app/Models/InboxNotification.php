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
     * Escalation notices contain cross-company operator identifiers; access
     * depends on the recipient's CURRENT role, not just who received them.
     * This single scope also constrains counters and read/unread mutations.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $query->where('user_id', $actor->id)
            ->when($actor->role !== 'admin',
                fn (Builder $q) => $q->where('kind', '!=',
                    'charging.fault.escalated.simulated'));
    }
}
