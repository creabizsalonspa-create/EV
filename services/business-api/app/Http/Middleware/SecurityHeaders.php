<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $r = $next($request);
        $r->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('X-Frame-Options', 'DENY');
        $sensitiveLink = $request->is('email/verify/*', 'reset-password/*');
        $r->headers->set('Referrer-Policy', $sensitiveLink ? 'no-referrer' : 'strict-origin-when-cross-origin');
        if ($request->isSecure()) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($request->is('api/*') || $sensitiveLink) {
            $r->headers->set('Cache-Control', 'no-store, private');
        }

        return $r;
    }
}
