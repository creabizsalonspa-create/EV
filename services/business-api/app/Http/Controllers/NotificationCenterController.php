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
        // All fields derive from the same tenant-scoped SQL snapshot. Unknown
        // kinds are grouped in "other", not silently lost.
        $fields = [
            'support' => 'support.%',
            'vehicle' => 'vehicle.%',
            'charging' => 'charging.%',
            'fleet' => 'fleet.%',
        ];
        $query = InboxNotification::query()
            ->where('user_id', $request->user()->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END), 0) AS unread_count, COALESCE(MAX(id), 0) AS latest_id');

        foreach ($fields as $category => $prefix) {
            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN read_at IS NULL AND kind LIKE ? THEN 1 ELSE 0 END), 0) AS unread_{$category}",
                [$prefix]
            );
        }
        $snapshot = $query->firstOrFail();
        $totals = [];
        foreach (array_keys($fields) as $category) {
            $totals[$category] = (int) $snapshot->{"unread_{$category}"};
        }
        $classified = array_sum($totals);
        $unread = (int) $snapshot->unread_count;
        $totals['other'] = max(0, $unread - $classified);

        return response()->json([
            'unread_count' => $unread,
            'latest_id' => (int) $snapshot->latest_id,
            'unread_by_category' => $totals,
            'in_app_only' => true,
        ])->header('Cache-Control', 'private, no-store');
    }

    /** Persistent inbox defaults; never hides operational events or enables delivery. */
    public function preferences(Request $request): JsonResponse
    {
        $record = DB::table('notification_preferences')
            ->where('user_id', $request->user()->id)->first();

        return response()->json($this->preferencePayload($record))
            ->header('Cache-Control', 'private, no-store');
    }

    /** Desired-state replace; safe to retry when a prior response was lost. */
    public function updatePreferences(Request $request): JsonResponse
    {
        $values = $request->validate([
            'default_category' => 'required|in:all,support,vehicle,charging,fleet',
            'default_unread_only' => 'required|boolean',
            'remind_unresolved_simulator_faults' => 'required|boolean',
            // Optional for older mobile/web clients; omit to preserve the
            // existing account choice rather than resetting it on PUT.
            'fault_reminder_delay_minutes' => 'sometimes|integer|in:15,30,60',
        ]);
        $desired = [
            'default_category' => $values['default_category'],
            'default_unread_only' => $request->boolean('default_unread_only'),
            'remind_unresolved_simulator_faults' => $request->boolean('remind_unresolved_simulator_faults'),
        ];

        return DB::transaction(function () use ($request, $desired, $values): JsonResponse {
            $owner = $request->user()->id;
            DB::table('notification_preferences')->insertOrIgnore([
                'user_id' => $owner,
                'default_category' => 'all',
                'default_unread_only' => false,
                'remind_unresolved_simulator_faults' => true,
                'fault_reminder_delay_minutes' => 15,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $current = DB::table('notification_preferences')
                ->where('user_id', $owner)->lockForUpdate()->first();
            abort_unless($current, 409);
            $desired['fault_reminder_delay_minutes'] = array_key_exists(
                'fault_reminder_delay_minutes', $values
            ) ? (int) $values['fault_reminder_delay_minutes']
              : (int) $current->fault_reminder_delay_minutes;
            $changed = $current->default_category !== $desired['default_category']
                || (bool) $current->default_unread_only !== $desired['default_unread_only']
                || (bool) $current->remind_unresolved_simulator_faults
                    !== $desired['remind_unresolved_simulator_faults']
                || (int) $current->fault_reminder_delay_minutes
                    !== $desired['fault_reminder_delay_minutes'];
            if ($changed) {
                DB::table('notification_preferences')->where('user_id', $owner)
                    ->update($desired + ['updated_at' => now()]);
                DB::table('audit_events')->insert([
                    'actor_id' => $owner,
                    'action' => 'notifications.preferences_changed',
                    'subject' => (string) $owner,
                    'created_at' => now(),
                ]);
            }
            $record = DB::table('notification_preferences')->where('user_id', $owner)->first();

            return response()->json($this->preferencePayload($record))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    private function preferencePayload(?object $row): array
    {
        return [
            'default_category' => $row?->default_category ?? 'all',
            'default_unread_only' => (bool) ($row?->default_unread_only ?? false),
            'remind_unresolved_simulator_faults' => (bool) ($row?->remind_unresolved_simulator_faults ?? true),
            'fault_reminder_delay_minutes' => (int) ($row?->fault_reminder_delay_minutes ?? 15),
            'in_app_only' => true,
            'external_delivery_available' => false,
            'critical_events_always_visible' => true,
        ];
    }

    /**
     * Mark only records that already exist at the customer's observed cursor.
     * Newly inserted notifications (higher IDs) remain unread. The operation
     * is idempotent and cannot affect notifications owned by another account.
     */
    public function readThrough(Request $request): JsonResponse
    {
        $v = $request->validate([
            'through_id' => 'required|integer|min:1|max:9007199254740991',
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
            ])->header('Cache-Control', 'private, no-store');
        });
    }
    /** Reopens only the caller's in-app read state; does not change the event. */
    public function markUnread(Request $request, InboxNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        return DB::transaction(function () use ($request, $notification): JsonResponse {
            $updated = InboxNotification::query()
                ->whereKey($notification->id)
                ->where('user_id', $request->user()->id)
                ->whereNotNull('read_at')
                ->update(['read_at' => null]);

            if ($updated > 0) {
                DB::table('audit_events')->insert([
                    'actor_id' => $request->user()->id,
                    'action' => 'notifications.unread_one',
                    'subject' => (string) $notification->id,
                    'created_at' => now(),
                ]);
            }

            return response()->json(
                InboxNotification::query()->whereKey($notification->id)
                    ->where('user_id', $request->user()->id)->firstOrFail()
            )->header('Cache-Control', 'private, no-store');
        });
    }

}
