<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Operations\OperationalHealth;
use App\Services\Operations\OperationalIncidents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminOperationalHealthController extends Controller
{
    private function currentAdmin(Request $request): User
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User, 401);
        $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->role === 'admin' && $current->account_status === 'active', 403);
        abort_unless($snapshot->role === $current->role && (int) $snapshot->auth_epoch === (int) $current->auth_epoch
            && hash_equals((string) $snapshot->password, (string) $current->password), 409, 'Account credentials changed; authenticate again.');

        return $current;
    }

    public function acknowledge(Request $request, OperationalIncidents $incidents)
    {
        return DB::transaction(function () use ($request, $incidents) {
            $actor = $this->currentAdmin($request);
            $values = $request->validate([
                'condition_key' => 'required|string|max:40',
                'transition_revision' => 'required|integer|min:1|max:2147483647',
            ]);

            return response()->json($incidents->acknowledge($actor, $values['condition_key'], (int) $values['transition_revision']))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function updateSubscription(Request $request, OperationalIncidents $incidents)
    {
        return DB::transaction(function () use ($request, $incidents) {
            $actor = $this->currentAdmin($request);
            $request->validate(['enabled' => 'required|boolean', 'user_id' => 'prohibited']);

            return response()->json(['enabled' => $incidents->subscribe($actor, $request->boolean('enabled'))])
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function show(Request $request, OperationalHealth $health)
    {
        return DB::transaction(function () use ($request, $health) {
            $incidents = app(OperationalIncidents::class);
            $actor = $this->currentAdmin($request);
            $report = $health->report();
            $report['incidents'] = $incidents->summary();
            if ($report['incidents']['store_readable']) {
                $report['incidents']['inbox_alerts_enabled'] = $incidents->subscribed($actor);
            }

            return response()->json($report)->header('Cache-Control', 'private, no-store');
        });
    }
}
