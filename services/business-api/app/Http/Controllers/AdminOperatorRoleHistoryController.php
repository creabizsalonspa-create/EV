<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOperatorRoleHistoryController extends Controller
{
    /**
     * Read-only inspection of privileged CLI role changes. The source ledger
     * intentionally identifies the target, role transition and ticket, not an
     * imaginary authenticated actor for shell-run operations.
     */
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->role === 'admin', 403);
        $filters = $request->validate([
            'target_user_id' => 'sometimes|integer|min:1',
            'assigned_role' => 'sometimes|in:customer,vendor,admin',
            'before_id' => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        $limit = (int) ($filters['limit'] ?? 25);

        $query = DB::table('operator_role_changes')
            ->select([
                'id', 'target_user_id', 'previous_role', 'assigned_role',
                'source', 'ticket_reference', 'created_at',
            ]);
        if (isset($filters['target_user_id'])) {
            $query->where('target_user_id', (int) $filters['target_user_id']);
        }
        if (isset($filters['assigned_role'])) {
            $query->where('assigned_role', $filters['assigned_role']);
        }
        if (isset($filters['before_id'])) {
            $query->where('id', '<', (int) $filters['before_id']);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit)->values();
        $targets = User::whereIn('id', $visible->pluck('target_user_id'))
            ->get(['id', 'name', 'email'])->keyBy('id');

        // Reading privileged identity-change history is itself auditable.
        // Do not treat an optional ticket reference as verified operator ID.
        DB::table('audit_events')->insert([
            'actor_id' => $actor->id,
            'action' => 'admin.operator_role_history.viewed',
            'subject' => isset($filters['target_user_id'])
                ? (string) $filters['target_user_id'] : 'all',
            'created_at' => now(),
        ]);

        return response()->json([
            'events' => $visible->map(function ($row) use ($targets) {
                $target = $targets->get($row->target_user_id);

                return [
                    'id' => (int) $row->id,
                    'target_user_id' => (int) $row->target_user_id,
                    'target_name' => $target?->name,
                    'target_email' => $target?->email,
                    'previous_role' => $row->previous_role,
                    'assigned_role' => $row->assigned_role,
                    'source' => $row->source,
                    'ticket_reference' => $row->ticket_reference,
                    'created_at' => $row->created_at,
                ];
            })->all(),
            'has_more' => $hasMore,
            'next_before_id' => $hasMore && $visible->isNotEmpty()
                ? (int) $visible->last()->id : null,
            'limit' => $limit,
            'operator_identity_available' => false,
        ])->header('Cache-Control', 'no-store, private');
    }
}
