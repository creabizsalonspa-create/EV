<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationCenterController extends Controller
{
    /**
     * Account-scoped counters. No notification contents or foreign account IDs
     * appear in this summary. The high-water mark is a database record ID, not
     * an event timestamp or evidence of message delivery.
     */
    public function summary(Request $request): JsonResponse
    {
        $scope = InboxNotification::query()->where('user_id', $request->user()->id);

        return response()->json([
            'unread_count' => (clone $scope)->whereNull('read_at')->count(),
            'latest_id' => (int) ((clone $scope)->max('id') ?? 0),
            'in_app_only' => true,
        ]);
    }

    /**
     * Mark only records that already exist at the customer's observed cursor.
     * Newly inserted notifications (higher IDs) remain unread. The operation
     * is idempotent and cannot affect notifications owned by another account.
     */
    public function readThrough(Request $request): JsonResponse
    {
        $v = $request->validate([
            'through_id' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($request, $v): JsonResponse {
            $scope = InboxNotification::query()->where('user_id', $request->user()->id);
            $updated = (clone $scope)->where('id', '<=', $v['through_id'])
                ->whereNull('read_at')->update(['read_at' => now()]);

            if ($updated > 0) {
                DB::table('audit_events')->insert([
                    'actor_id' => $request->user()->id,
                    'action' => 'notifications.read_through',
                    'subject' => (string) $v['through_id'],
                    'created_at' => now(),
                ]);
            }

            return response()->json([
                'marked_read' => $updated,
                'through_id' => (int) $v['through_id'],
                'remaining_unread' => (clone $scope)->whereNull('read_at')->count(),
                'in_app_only' => true,
            ]);
        });
    }
}
