<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAuditTrailController extends Controller
{
    /**
     * Cursor-based, read-only audit inspection. Existing audited operations
     * remain authoritative; this endpoint never creates or edits entries.
     */
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);

        $input = $request->validate([
            'action_prefix' => ['sometimes', 'required', 'string', 'max:80',
                'regex:/^[A-Za-z0-9._-]+$/D'],
            'actor_id' => 'sometimes|integer|min:1',
            'before_id' => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        $limit = (int) ($input['limit'] ?? 50);
        $query = DB::table('audit_events')
            ->select(['id', 'actor_id', 'action', 'subject', 'created_at']);
        if (isset($input['action_prefix'])) {
            // An underscore is legal in event names but is a wildcard in
            // SQL LIKE. Use a bound literal prefix, portable across SQLite,
            // PostgreSQL and MySQL, so no event category leaks into a filter.
            $prefix = $input['action_prefix'];
            $query->whereRaw('SUBSTR(action, 1, ?) = ?', [strlen($prefix), $prefix]);
        }
        if (isset($input['actor_id'])) {
            $query->where('actor_id', (int) $input['actor_id']);
        }
        if (isset($input['before_id'])) {
            $query->where('id', '<', (int) $input['before_id']);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $visible = $rows->take($limit)->values();
        $actors = User::whereIn('id', $visible->pluck('actor_id'))
            ->get(['id', 'name'])->keyBy('id');

        return response()->json([
            'events' => $visible->map(fn ($row) => [
                'id' => (int) $row->id,
                'action' => $row->action,
                'actor_id' => (int) $row->actor_id,
                'actor_name' => $actors->get($row->actor_id)?->name,
                'subject' => $row->subject,
                'created_at' => $row->created_at,
            ])->all(),
            'has_more' => $more,
            'next_before_id' => $more && $visible->isNotEmpty()
                ? (int) $visible->last()->id : null,
            'limit' => $limit,
        ])->header('Cache-Control', 'no-store, private');
    }
}
