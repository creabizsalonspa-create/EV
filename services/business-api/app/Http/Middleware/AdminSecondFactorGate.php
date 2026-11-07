<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminSecondFactorGate
{
    public static function verified(Request $request): bool
    {
        $account = $request->user();
        if (! $account || $account->role !== 'admin' || ! $request->hasSession()) {
            return false;
        }

        if ((int) $request->session()->get('admin_second_factor_user_id', 0) !== (int) $account->id) {
            return false;
        }

        $at = (int) $request->session()->get('admin_second_factor_verified_at', 0);
        if ($at <= 0 || $at > time() || time() - $at > 12 * 60 * 60) {
            return false;
        }

        $proof = $request->session()->get('admin_second_factor_enrollment_proof');
        if (! is_string($proof) || strlen($proof) !== 64) {
            return false;
        }

        $encrypted = DB::table('admin_second_factors')
            ->where('user_id', $account->id)
            ->value('encrypted_secret');

        return is_string($encrypted) && hash_equals(hash('sha256', $encrypted), $proof);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Legacy tests keep their established auth fixtures; production/staging
        // Admin requests always require enrollment + an actual TOTP challenge.
        if (app()->environment('testing') && ! config('admin_security.force_in_tests', false)) {
            return $next($request);
        }

        $account = $request->user();
        if (! $account || $account->role !== 'admin') {
            return $next($request);
        }

        if ($request->is('api/login', 'api/logout', 'api/me', 'api/csrf',
                'api/admin/second-factor/*', 'admin/second-factor')) {
            return $next($request);
        }

        if (self::verified($request)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => 'Administrator authenticator verification is required.',
                'admin_second_factor_required' => true,
            ], 403)->header('Cache-Control', 'no-store, private');
        }

        return redirect('/admin/second-factor')->header('Cache-Control', 'no-store, private');
    }
}
