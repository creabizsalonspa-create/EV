<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function sendVerification(Request $r)
    {
        if ($r->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already verified.']);
        }
        // Explicit opt-in keeps registration independent of mail-provider outages.
        // Do not return the signed link: ownership must be proved through the mailbox.
        $r->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link requested. Check your email; the link expires in 60 minutes.'], 202);
    }

    public function verifyEmail(EmailVerificationRequest $r)
    {
        $verified = DB::transaction(function () use ($r) {
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(sha1($user->getEmailForVerification()), (string) $r->route('hash')), 403);
            if ($user->hasVerifiedEmail()) {
                return null;
            }
            $user->markEmailAsVerified();
            DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'email.verified', 'subject' => (string) $user->id, 'created_at' => now()]);

            return $user;
        });
        if ($verified) {
            event(new Verified($verified));
        }

        return response()->view('email-verified')->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function forgot(Request $r)
    {
        $v = $r->validate(['email' => 'required|email']);
        Password::sendResetLink($v);

        return response()->json(['message' => 'If eligible, a password reset link has been sent.'], 202);
    }

    public function reset(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => 'required|string|min:12|max:128|confirmed']);
        $status = Password::reset($v, function (User $user, string $password) use ($r) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            if ($r->user()?->id === $user->id) {
                Auth::logout();
                $r->session()->invalidate();
                $r->session()->regenerateToken();
            }
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'Invalid or expired reset link.']);
        }

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }

    public function profile(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:100', 'locale' => 'required|in:en,ar']);
        $r->user()->forceFill($v)->save();

        return response()->json($r->user());
    }
}
