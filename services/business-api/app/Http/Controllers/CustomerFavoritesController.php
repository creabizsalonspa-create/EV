<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Favorites are database-only preferences; serialize them with account revocation. */
abstract class CustomerFavoritesController extends Controller
{
    public function callAction($method, $parameters)
    {
        $request = collect($parameters)->first(fn ($value) => $value instanceof Request);
        abort_unless($request?->user(), 401);

        return DB::transaction(function () use ($request, $method, $parameters) {
            $snapshot = $request->user();
            $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($snapshot->role === 'customer' && $current->role === 'customer' && $current->account_status === 'active', 403);
            abort_unless((int) $current->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $current->password, (string) $snapshot->password), 409, 'Account credentials changed; authenticate again.');

            return $this->{$method}(...array_values($parameters));
        }, 3);
    }

    protected function customer(Request $request): int
    {
        abort_unless($request->user()->role === 'customer', 403);

        return $request->user()->id;
    }
}
