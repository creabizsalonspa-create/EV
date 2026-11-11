<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reads must not reissue an unchanged cookie over a newer account session. */
class StableReadSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // Place after cookie decryption but before StartSession. The inner
        // response still has plaintext cookie values at this boundary.
        $name = config('session.cookie');
        $incoming = is_string($name) ? $request->cookie($name) : null;
        $response = $next($request);
        if (! in_array($request->method(), ['GET', 'HEAD'], true)
            || ! is_string($name) || $name === '' || ! is_string($incoming)
            || $incoming === '' || ! $request->hasSession()
            || ! hash_equals($incoming, $request->session()->getId())) {
            return $response;
        }
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name && hash_equals($incoming, (string) $cookie->getValue())) {
                $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }
}
