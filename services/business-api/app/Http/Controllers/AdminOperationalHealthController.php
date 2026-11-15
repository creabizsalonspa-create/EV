<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Operations\OperationalHealth;
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

            return response()->json($health->report())->header('Cache-Control', 'private, no-store');
        });
    }
}
