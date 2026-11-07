<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AccountStatusGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // Native Sanctum may authenticate after this group-level middleware.
        // Resolve only v1 identity here; public discovery is unaffected.
        $account = $request->is('api/v1/*')
            ? Auth::guard('sanctum')->user()
            : $request->user();

        if ($account?->account_status !== 'suspended') {
            return $next($request);
        }

        // Revoked sessions normally fail authentication first, but preserve a
        // minimal self-logout route if a request was already authenticated.
        if ($request->isMethod('POST')
            && $request->is('api/logout', 'api/v1/logout')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Account access is unavailable.'], 403)
                ->header('Cache-Control', 'no-store, private');
        }

        return response('Account access is unavailable.', 403)
            ->header('Cache-Control', 'no-store, private');
    }
}
