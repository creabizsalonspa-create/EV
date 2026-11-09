<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

class AccountStatusGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fresh sign-in, sign-out, CSRF, registration and account recovery
        // must remain reachable even when a stale session is presented.
        // Credential issuance itself validates the current account status.
        if ($request->is(
            'api/login', 'api/logout', 'api/register', 'api/csrf',
            'api/forgot-password', 'api/reset-password'
        )) {
            return $next($request);
        }

        // Native Sanctum authorization runs after this middleware. Resolve
        // that actor only for v1 requests; bearer revocation is enforced by
        // the same database-backed account status.
        $native = $request->is('api/v1/*');
        $account = $native
            ? Auth::guard('sanctum')->user()
            : $request->user();

        if ($account?->account_status === 'suspended') {
            if ($request->isMethod('POST') && $request->is('api/v1/logout')) {
                return $next($request);
            }
            return response()->json(['message' => 'Account access is unavailable.'], 403)
                ->header('Cache-Control', 'no-store, private');
        }

        // After a moderation action, rotating account_auth_epoch invalidates
        // old file/array/Redis sessions, not just database-backed sessions.
        // Existing unmoderated accounts (epoch=0) remain compatible.
        // Sanctum may authenticate first-party API requests using a browser
        // session, not a mobile bearer token. Those requests must observe the
        // same auth epoch as regular web routes. Genuine personal access tokens
        // are revoked separately and do not carry browser session epochs.
        $usesBrowserSession = $native === false || ($account
            && $account->currentAccessToken() instanceof TransientToken);
        if ($usesBrowserSession && $account && (int) $account->auth_epoch > 0
            && ($request->hasSession() === false
                || (int) $request->session()->get('account_auth_epoch', -1)
                    !== (int) $account->auth_epoch)) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Sign in again to continue.'], 401)
                    ->header('Cache-Control', 'no-store, private');
            }

            return redirect('/?session=expired')->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
