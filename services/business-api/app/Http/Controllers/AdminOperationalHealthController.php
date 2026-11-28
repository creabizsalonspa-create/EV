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

    public function show(Request $request, OperationalHealth $health)
    {
        return DB::transaction(function () use ($request, $health) {
            $this->currentAdmin($request);
            $cursor = $request->validate(['before_event_id' => 'sometimes|required|integer|min:1|max:9007199254740991']);
            $report = $health->report();
            $report['incidents'] = app(OperationalIncidents::class)->summary(
                isset($cursor['before_event_id']) ? (int) $cursor['before_event_id'] : null,
            );

            return response()->json($report)->header('Cache-Control', 'private, no-store');
        });
    }
}
