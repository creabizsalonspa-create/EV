<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminSessionController extends Controller
{
    /**
     * End all of this administrator's sessions, including file/Redis-backed
     * browser sessions that cannot be enumerated from the SQL session table.
     * Revocation cannot be undone by merely presenting an old session cookie.
     */
    public function revokeAll(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User
            && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);

        $input = $request->validate([
            'password' => 'required|string|max:128',
        ]);

        DB::transaction(function () use ($actor, $input) {
            // Revalidate current credentials and role after locking the user.
            // In particular, a concurrent password reset must not allow a
            // previously known password to revoke the new owner's sessions.
            $account = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($account->role === 'admin'
                && $account->account_status === 'active', 403);
            if (! Hash::check($input['password'], $account->password)) {
                throw ValidationException::withMessages([
                    'password' => 'Unable to verify account credentials.',
                ]);
            }

            $account->forceFill([
                'auth_epoch' => (int) $account->auth_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();
            $account->tokens()->delete();
            DB::table('sessions')->where('user_id', $account->id)->delete();

            DB::table('audit_events')->insert([
                'actor_id' => $account->id,
                'action' => 'admin.sessions.revoked_all',
                'subject' => (string) $account->id,
                'created_at' => now(),
            ]);
        });

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Deliberately return no tokens or private browser/session inventory.
        return response()->noContent()
            ->header('Cache-Control', 'no-store, private');
    }
}
