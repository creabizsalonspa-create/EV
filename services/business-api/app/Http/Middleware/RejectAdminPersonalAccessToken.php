<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RejectAdminPersonalAccessToken
{
    /**
     * A vendor/customer mobile token must never acquire Admin authority if
     * its account's server-side role is promoted after issuance.
     *
     * Sanctum web-session authentication uses a transient token, not a
     * persisted PersonalAccessToken; legitimate Admin web sessions remain
     * separate from the mobile token boundary.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->role === 'admin' && $user->currentAccessToken() instanceof PersonalAccessToken) {
            // A stale mobile token may delete itself, but it may not read or
            // mutate any authenticated Admin/customer/vendor API resource.
            if (! ($request->is('api/v1/logout') && $request->isMethod('POST'))) {
                abort(403, 'Admin accounts require the web administration portal.');
            }
        }

        return $next($request);
    }
}
