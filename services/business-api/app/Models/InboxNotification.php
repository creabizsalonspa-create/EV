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
        // A stale authenticated User can retain the former Admin role.
        // Perform the authoritative role check in the SAME database
        // statement as inbox reads/updates, not a separate preflight query:
        // a role revoked after building this query must never grant access.
        return $query->where('user_id', $actor->id)
            ->where(function (Builder $visible): void {
                $visible->whereNotIn('kind', [
                    'charging.fault.escalated.simulated',
                    'charging.fault.escalation_closed.simulated',
                ])->orWhereExists(function ($active) {
                    $active->selectRaw('1')->from('users as active_admin')
                        ->whereColumn('active_admin.id', 'inbox_notifications.user_id')
                        ->where('active_admin.role', 'admin');
                });
            });
    }
}
