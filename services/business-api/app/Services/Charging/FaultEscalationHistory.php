<?php

namespace App\Services\Charging;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Recipient-scoped historical receipts; caller holds current admin's account lock. */
final class FaultEscalationHistory
{
    public function read(User $admin, ?int $before): array
    {
        abort_unless($admin->role === 'admin' && $admin->account_status === 'active', 403);
        $scope = [
            'scope' => 'ASSIGNED_SIMULATOR_ESCALATIONS',
            'current_fault_state_verified' => false,
            'physical_verification' => false,
        ];
        try {
            return DB::transaction(function () use ($admin, $before, $scope) {
                $rows = DB::table('charging_fault_admin_escalations')
                    ->where('admin_user_id', $admin->id)
                    ->when($before !== null, fn ($q) => $q->where('alert_id', '<', $before))
                    ->orderByDesc('alert_id')->limit(26)
                    ->get(['alert_id', 'created_at', 'resolved_at', 'resolution_source']);
                $clock = now('UTC');
                $page = $rows->take(25)->values();

                return $scope + [
                    'store_readable' => true,
                    'data' => $page->map(fn ($row) => FaultEscalationReceipt::project($row, $clock))->all(),
                    'next_before' => $rows->count() > 25 ? (string) $page->last()->alert_id : null,
                ];
            });
        } catch (Throwable) {
            // A missing or unreadable history table is not proof of no
            // incidents. Roll back the nested savepoint on PostgreSQL.
            return $scope + ['store_readable' => false, 'data' => [], 'next_before' => null];
        }
    }
}
