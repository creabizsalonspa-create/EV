<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class MobileAuthController extends Controller
{
    private function issue(User $u)
    {
        return DB::transaction(function () use ($u) {
            // Revalidate the current role and suspension state under a row
            // lock so a concurrent Admin promotion or suspension cannot mint
            // a fresh bearer token from an obsolete User instance.
            $account = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($account->role, ['customer', 'vendor']), 403, 'Admin accounts use the web administration portal.');
            abort_unless($account->account_status === 'active', 403, 'Account access is unavailable.');

            // A login checked before revocation/reset cannot mint a token
            // after the account's authorization boundary has moved.
            abort_unless((int) $account->auth_epoch === (int) $u->auth_epoch
                && hash_equals((string) $account->password, (string) $u->password), 409, 'Account credentials changed; sign in again.');

            $account->tokens()->where('created_at', '<', now()->subDay())->delete();
            if ($account->tokens()->count() >= 5) {
                $account->tokens()->oldest('id')->first()->delete();
            }
            $token = $account->createToken('mobile', ['platform'], now()->addDay());

            return response()->json(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at, 'user' => $account]);
        });
    }

    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $u = User::where('email', $v['email'])->first();
        if (! $u || ! Hash::check($v['password'], $u->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        return $this->issue($u);
    }

    public function register(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254|unique:users', 'password' => 'required|string|min:12|max:128']);
        $u = User::create(['name' => $v['name'], 'email' => $v['email'], 'password' => Hash::make($v['password'])]);

        return $this->issue($u)->setStatusCode(201);
    }

    public function logout(Request $r)
    {
        $token = $r->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);
        $token->delete();

        return response()->noContent();
    }
}
