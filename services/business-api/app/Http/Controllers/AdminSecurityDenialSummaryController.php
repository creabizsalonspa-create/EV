<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\AdminTransactionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminSecurityDenialSummaryController extends Controller
{
    /**
     * Evidence-based subset of Admin MFA denials. No threat scoring,
     * inferred account compromise, or automatic enforcement.
     */
    private const ACTIONS = [
        'admin.second_factor.enrollment_password_denied',
        'admin.second_factor.enrollment_code_denied',
        'admin.second_factor.verify_denied',
        'admin.second_factor.recovery_denied',
        'admin.second_factor.recovery_rotation_denied',
        'admin.second_factor.recovery_reset_password_denied',
    ];

    public function index(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $snapshot = $request->user();
            abort_unless($snapshot instanceof User
                && $snapshot->role === 'admin'
                && $snapshot->account_status === 'active', 403);
            $current = AdminTransactionAuthority::requireCurrent($snapshot);

            $v = $request->validate([
                'window' => 'sometimes|required|in:1h,24h,7d',
                'actor_id' => ['sometimes', 'bail', 'regex:/\A[1-9][0-9]{0,15}\z/', 'integer', 'between:1,9007199254740991'],
            ]);
            $window = $v['window'] ?? '24h';
            $hours = match ($window) {
                '1h' => 1,
                '24h' => 24,
                '7d' => 168,
            };
            $asOf = now();
            $from = $asOf->copy()->subHours($hours);
            $limit = 500;

            $query = DB::table('audit_events')
                ->whereIn('action', self::ACTIONS)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<=', $asOf)
                ->orderByDesc('id')
                ->limit($limit + 1);
            if (isset($v['actor_id'])) {
                $query->where('actor_id', (int) $v['actor_id']);
            }
            $rows = $query->get(['id', 'actor_id', 'action', 'created_at']);
            $sampleTruncated = $rows->count() > $limit;
            $visible = $rows->take($limit);

            $actionCounts = array_fill_keys(self::ACTIONS, 0);
            $actorCounts = [];
            foreach ($visible as $event) {
                $actionCounts[$event->action]++;
                if ($event->actor_id !== null) {
                    $id = (int) $event->actor_id;
                    $actorCounts[$id] = ($actorCounts[$id] ?? 0) + 1;
                }
            }
            // Stable tie order is actor ID ascending, not database encounter order.
            ksort($actorCounts, SORT_NUMERIC);
            arsort($actorCounts, SORT_NUMERIC);
            $actors = [];
            foreach (array_slice($actorCounts, 0, 25, true) as $id => $count) {
                $actors[] = ['actor_id' => (int) $id, 'observed_denials' => $count];
            }

            // Successful inspection is auditable without storing private
            // account names, raw requests, factor codes or source payloads.
            DB::table('audit_events')->insert([
                'actor_id' => $current->id,
                'action' => 'admin.private_list.security_denials_viewed',
                'subject' => isset($v['actor_id'])
                    ? $window.':actor:'.$v['actor_id'] : $window.':all',
                'created_at' => now(),
            ]);

            return response()->json([
                'window' => $window,
                'actor_id' => isset($v['actor_id']) ? (int) $v['actor_id'] : null,
                'from' => $from->toISOString(),
                'as_of' => $asOf->toISOString(),
                'source' => 'existing_admin_mfa_denial_audit_only',
                'observed_denials' => $visible->count(),
                'per_action' => $actionCounts,
                'top_actors' => $actors,
                'sample_limit' => $limit,
                'sample_truncated' => $sampleTruncated,
                'complete_for_selected_actions' => ! $sampleTruncated,
                'suspicious_activity_verified' => false,
                'incident_confirmed' => false,
                'automated_action_taken' => false,
            ])->header('Cache-Control', 'no-store, private');
        });
    }
}
