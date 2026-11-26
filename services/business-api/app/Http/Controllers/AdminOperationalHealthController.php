<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Charging\FaultEscalationHistory;
use App\Services\Operations\OperationalHealth;
use App\Services\Operations\OperationalIncidents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminOperationalHealthController extends Controller
{
    public function show(Request $request, OperationalHealth $health)
    {
        return DB::transaction(function () use ($request, $health) {
            $snapshot = $request->user();
            $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->role === 'admin' && $current->account_status === 'active', 403);
            abort_unless($snapshot->role === $current->role && (int) $snapshot->auth_epoch === (int) $current->auth_epoch
                && hash_equals((string) $snapshot->password, (string) $current->password), 409, 'Account credentials changed; authenticate again.');

            $v = $request->validate([
                'fault_before' => ['sometimes', 'required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            ]);
            $before = isset($v['fault_before']) ? (int) $v['fault_before'] : null;
            abort_if($before !== null && (string) $before !== $v['fault_before'], 422);
            $report = $health->report();
            $report['incidents'] = app(OperationalIncidents::class)->summary();
            $report['fault_escalations'] = app(FaultEscalationHistory::class)->read($current, $before);

            return response()->json($report)->header('Cache-Control', 'private, no-store');
        });
    }
}
