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
            // Check the *locked current role*, not a User instance retrieved
            // before a concurrent promotion to Admin.
            $account = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($account->role, ['customer', 'vendor']), 403, 'Admin accounts use the web administration portal.');

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
