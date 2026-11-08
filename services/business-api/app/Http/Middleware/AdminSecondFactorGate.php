<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminSecondFactorGate
{
    public static function verified(Request $request, ?object $resolvedAccount = null): bool
    {
        $account = $resolvedAccount ?? $request->user();
        if (! $account || $account->role !== 'admin' || ! $request->hasSession()) {
            return false;
        }

        // A password-reset-only recovery session can never authorize ordinary
        // Admin operations, even if a stale challenge grant is still present.
        if ($request->session()->has('admin_second_factor_recovery_user_id')) {
            return false;
        }

        if ((int) $request->session()->get('admin_second_factor_user_id', 0) !== (int) $account->id) {
            return false;
        }

        $at = (int) $request->session()->get('admin_second_factor_verified_at', 0);
        if ($at <= 0 || $at > now()->timestamp || now()->timestamp - $at > 12 * 60 * 60) {
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

    /**
     * Recovery is deliberately NOT an Admin MFA grant. A consumed code can
     * authorize only password-confirmed authenticator replacement for ten
     * minutes, bound to the same account and current factor enrollment.
     */
    public static function recoveryResetAvailable(Request $request): bool
    {
        $account = $request->user();
        if (! $account || $account->role !== 'admin' || ! $request->hasSession()) {
            return false;
        }

        if ((int) $request->session()->get('admin_second_factor_recovery_user_id', 0) !== (int) $account->id) {
            return false;
        }

        $at = (int) $request->session()->get('admin_second_factor_recovery_at', 0);
        if ($at <= 0 || $at > now()->timestamp || now()->timestamp - $at > 10 * 60) {
            return false;
        }

        $proof = $request->session()->get('admin_second_factor_recovery_enrollment_proof');
        if (! is_string($proof) || strlen($proof) !== 64) {
            return false;
        }

        $encrypted = DB::table('admin_second_factors')
            ->where('user_id', $account->id)->value('encrypted_secret');

        return is_string($encrypted) && hash_equals(hash('sha256', $encrypted), $proof);
    }

    public static function required(): bool
    {
        // Existing local/synthetic CI is kept compatible. Production and
        // staging cannot opt out through an environment flag.
        return ! app()->environment(['local', 'testing'])
            || config('admin_security.enforce_in_local_tests', false);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::required()) {
            return $next($request);
        }

        if ($request->is('api/v1/*')) {
            // The API middleware runs before each route's auth:sanctum.
            // Resolve the actual Sanctum actor now so a session-backed Admin
            // cannot slip around the browser-only MFA middleware.
            $nativeAccount = Auth::guard('sanctum')->user();
            if ($nativeAccount?->role === 'admin' && ! self::verified($request, $nativeAccount)) {
                return response()->json([
                    'message' => 'Administrator authenticator verification is required.',
                    'admin_second_factor_required' => true,
                ], 403)->header('Cache-Control', 'no-store, private');
            }

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
