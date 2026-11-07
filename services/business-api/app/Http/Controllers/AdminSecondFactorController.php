<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AdminSecondFactorGate;
use App\Models\User;
use App\Security\AdminTotp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminSecondFactorController extends Controller
{
    private function account(Request $request): User
    {
        $account = $request->user();
        abort_unless($account instanceof User && $account->role === 'admin', 403);

        return $account;
    }

    public function page(Request $request)
    {
        $this->account($request);

        return response()->view('admin-second-factor')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function status(Request $request)
    {
        $account = $this->account($request);
        $enrolled = DB::table('admin_second_factors')->where('user_id', $account->id)->exists();

        return response()->json([
            'enrolled' => $enrolled,
            'verified' => $enrolled && AdminSecondFactorGate::verified($request),
            'authenticator_required' => true,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function begin(Request $request)
    {
        $account = $this->account($request);
        $input = $request->validate(['password' => 'required|string|max:128']);
        if (! Hash::check($input['password'], $account->password)) {
            throw ValidationException::withMessages(['password' => 'Unable to verify account credentials.']);
        }
        abort_if(DB::table('admin_second_factors')->where('user_id', $account->id)->exists(), 409, 'Authenticator is already enrolled.');

        $secret = AdminTotp::generateSecret();
        $request->session()->put('admin_second_factor_pending', [
            'user_id' => $account->id,
            'encrypted_secret' => Crypt::encryptString($secret),
            'expires_at' => now()->timestamp + 600,
        ]);

        // The one-time enrollment secret is shown only after password
        // reauthentication. Never place it in an application log or audit.
        return response()->json([
            'secret' => $secret,
            'provisioning_uri' => AdminTotp::provisioningUri($secret, $account->email),
            'expires_in_seconds' => 600,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function confirm(Request $request)
    {
        $account = $this->account($request);
        $code = $this->code($request);
        $pending = $request->session()->get('admin_second_factor_pending');
        abort_unless(is_array($pending)
            && ($pending['user_id'] ?? null) === $account->id
            && is_string($pending['encrypted_secret'] ?? null)
            && (int) ($pending['expires_at'] ?? 0) >= now()->timestamp, 409, 'Enrollment expired. Restart authenticator setup.');

        $secret = Crypt::decryptString($pending['encrypted_secret']);
        $step = AdminTotp::matchingStep($secret, $code, now()->timestamp);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired authenticator code.']);
        }

        // High-entropy codes are shown once, after the authenticator itself is
        // confirmed. Only their SHA-256 digests ever enter the database.
        $recoveryCodes = array_map(
            fn () => bin2hex(random_bytes(10)),
            range(1, 8)
        );
        $encrypted = DB::transaction(function () use ($account, $secret, $step, $recoveryCodes) {
            User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('admin_second_factors')->where('user_id', $account->id)->exists(), 409, 'Authenticator is already enrolled.');
            $encrypted = Crypt::encryptString($secret);
            DB::table('admin_second_factors')->insert([
                'user_id' => $account->id,
                'encrypted_secret' => $encrypted,
                'last_accepted_step' => $step,
                'confirmed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($recoveryCodes as $code) {
                DB::table('admin_second_factor_recovery_codes')->insert([
                    'user_id' => $account->id,
                    'code_digest' => hash('sha256', $code),
                    'created_at' => now(),
                ]);
            }
            $this->audit($account, 'admin.second_factor.enrolled');

            return $encrypted;
        });

        $request->session()->forget('admin_second_factor_pending');
        $this->grant($request, $account, $encrypted);

        return response()->json([
            'verified' => true,
            'enrolled' => true,
            'recovery_codes' => $recoveryCodes,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request)
    {
        $account = $this->account($request);
        $code = $this->code($request);

        $encrypted = DB::transaction(function () use ($account, $code) {
            $record = DB::table('admin_second_factors')
                ->where('user_id', $account->id)
                ->lockForUpdate()->first();
            abort_unless($record, 409, 'Enroll an authenticator before verification.');

            $secret = Crypt::decryptString($record->encrypted_secret);
            $step = AdminTotp::matchingStep($secret, $code, now()->timestamp, (int) $record->last_accepted_step);
            if ($step === null) {
                throw ValidationException::withMessages(['code' => 'Invalid, expired or already used authenticator code.']);
            }

            DB::table('admin_second_factors')->where('user_id', $account->id)
                ->update(['last_accepted_step' => $step, 'updated_at' => now()]);
            $this->audit($account, 'admin.second_factor.verified');

            return $record->encrypted_secret;
        });

        $this->grant($request, $account, $encrypted);

        return response()->json(['verified' => true, 'enrolled' => true])
            ->header('Cache-Control', 'no-store, private');
    }

    private function code(Request $request): string
    {
        return $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']])['code'];
    }

    private function grant(Request $request, User $account, string $encrypted): void
    {
        // Promote to a fresh session identifier after successful MFA.
        $request->session()->regenerate();
        $request->session()->put('admin_second_factor_user_id', $account->id);
        $request->session()->put('admin_second_factor_verified_at', now()->timestamp);
        $request->session()->put('admin_second_factor_enrollment_proof', hash('sha256', $encrypted));
    }

    private function audit(User $account, string $event): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $account->id,
            'action' => $event,
            'subject' => (string) $account->id,
            'created_at' => now(),
        ]);
    }
}
