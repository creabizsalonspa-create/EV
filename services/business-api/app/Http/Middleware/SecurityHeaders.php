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
        // A route may deliberately return a more restrictive policy (for example,
        // payment-return pages). Do not silently replace its policy with the
        // platform's broader default after the controller has responded.
        $csp = $r->headers->get('Content-Security-Policy');
        if (! is_string($csp) || trim($csp) === '') {
            $r->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        }
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('X-Frame-Options', 'DENY');
        $sensitiveLink = $request->is('email/verify/*', 'reset-password/*', 'payments/hosted-return', 'payments/hosted-simulation/*', 'payments/corporate-hosted-return', 'payments/corporate-hosted-simulation/*');
        // A route's deliberate no-referrer policy is stronger than our default;
        // preserve it, but never accept a route's weaker/invalid policy or
        // allow sensitive payment and recovery URLs to disclose referrers.
        $explicitReferrer = $r->headers->get('Referrer-Policy');
        $keepNoReferrer = is_string($explicitReferrer) && strtolower(trim($explicitReferrer)) === 'no-referrer';
        $r->headers->set('Referrer-Policy', $sensitiveLink || $keepNoReferrer ? 'no-referrer' : 'strict-origin-when-cross-origin');
        if ($request->isSecure()) {
            $r->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($request->is('api/*') || $sensitiveLink) {
            $r->headers->set('Cache-Control', 'no-store, private');
        }

        return $r;
    }
}
