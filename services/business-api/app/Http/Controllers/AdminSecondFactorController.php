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
            'recovery_reset_available' => $enrolled && AdminSecondFactorGate::recoveryResetAvailable($request),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function begin(Request $request)
    {
        $account = $this->account($request);
        $input = $request->validate(['password' => 'required|string|max:128']);
        if (! Hash::check($input['password'], $account->password)) {
            $this->audit($account, 'admin.second_factor.enrollment_password_denied');
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
            $this->audit($account, 'admin.second_factor.enrollment_code_denied');
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
                // An exception here would roll back a failure audit written
                // inside this transaction. Report denial after it returns.
                return null;
            }

            DB::table('admin_second_factors')->where('user_id', $account->id)
                ->update(['last_accepted_step' => $step, 'updated_at' => now()]);
            $this->audit($account, 'admin.second_factor.verified');

            return $record->encrypted_secret;
        });

        if (! is_string($encrypted)) {
            $this->audit($account, 'admin.second_factor.verify_denied');
            throw ValidationException::withMessages([
                'code' => 'Invalid, expired or already used authenticator code.',
            ]);
        }
        $this->grant($request, $account, $encrypted);

        return response()->json(['verified' => true, 'enrolled' => true])
            ->header('Cache-Control', 'no-store, private');
    }

    public function recover(Request $request)
    {
        $account = $this->account($request);
        $code = $request->validate([
            'recovery_code' => ['required', 'string', 'regex:/^[a-fA-F0-9]{20}$/D'],
        ])['recovery_code'];
        $digest = hash('sha256', strtolower($code));

        $encrypted = DB::transaction(function () use ($account, $digest) {
            $record = DB::table('admin_second_factor_recovery_codes')
                ->where('user_id', $account->id)
                ->where('code_digest', $digest)
                ->whereNull('used_at')->lockForUpdate()->first();
            if (! $record) {
                return null;
            }

            $encrypted = DB::table('admin_second_factors')
                ->where('user_id', $account->id)->value('encrypted_secret');
            abort_unless(is_string($encrypted), 409, 'Authenticator enrollment is unavailable.');

            DB::table('admin_second_factor_recovery_codes')
                ->where('id', $record->id)->whereNull('used_at')
                ->update(['used_at' => now()]);
            $this->audit($account, 'admin.second_factor.recovered');

            return $encrypted;
        });

        if (! is_string($encrypted)) {
            $this->audit($account, 'admin.second_factor.recovery_denied');
            throw ValidationException::withMessages([
                'recovery_code' => 'Invalid or already used recovery code.',
            ]);
        }

        // A recovered session is purpose-limited: it MUST NOT unlock Admin
        // APIs until a fresh authenticator challenge succeeds.
        $request->session()->regenerate();
        $request->session()->forget([
            'admin_second_factor_user_id',
            'admin_second_factor_verified_at',
            'admin_second_factor_enrollment_proof',
            'admin_second_factor_pending',
        ]);
        $request->session()->put('admin_second_factor_recovery_user_id', $account->id);
        $request->session()->put('admin_second_factor_recovery_at', now()->timestamp);
        $request->session()->put(
            'admin_second_factor_recovery_enrollment_proof',
            hash('sha256', $encrypted)
        );

        return response()->json(['verified' => false, 'recovery_reset_available' => true])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Generate a fresh set of backup codes without changing the authenticator
     * secret or granting a new Admin session. Both a current verified MFA
     * session and fresh password+single-use TOTP are required.
     */
    public function rotateRecoveryCodes(Request $request)
    {
        $actor = $this->account($request);
        abort_unless(AdminSecondFactorGate::verified($request), 403,
            'Verify your authenticator before replacing recovery codes.');

        $input = $request->validate([
            'password' => 'required|string|max:128',
            'code' => ['required', 'string', 'regex:/^\d{6}$/D'],
        ]);

        $result = DB::transaction(function () use ($actor, $request, $input) {
            $account = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($account->role === 'admin', 403);

            $factor = DB::table('admin_second_factors')
                ->where('user_id', $account->id)->lockForUpdate()->first();
            $proof = $request->session()->get('admin_second_factor_enrollment_proof');
            // If an authenticator was replaced in another session since the
            // MFA grant, an old challenge must not modify its new code set.
            abort_unless($factor && is_string($proof)
                && hash_equals(hash('sha256', $factor->encrypted_secret), $proof),
                409, 'Authenticator changed. Verify it again.');

            if (! Hash::check($input['password'], $account->password)) {
                return ['denied' => 'password'];
            }
            $secret = Crypt::decryptString($factor->encrypted_secret);
            $step = AdminTotp::matchingStep(
                $secret, $input['code'], now()->timestamp,
                (int) $factor->last_accepted_step
            );
            if ($step === null) {
                return ['denied' => 'code'];
            }

            $codes = array_map(fn () => bin2hex(random_bytes(10)), range(1, 8));
            DB::table('admin_second_factor_recovery_codes')
                ->where('user_id', $account->id)->delete();
            foreach ($codes as $code) {
                DB::table('admin_second_factor_recovery_codes')->insert([
                    'user_id' => $account->id,
                    'code_digest' => hash('sha256', $code),
                    'created_at' => now(),
                ]);
            }
            DB::table('admin_second_factors')
                ->where('user_id', $account->id)
                ->update(['last_accepted_step' => $step, 'updated_at' => now()]);
            $this->audit($account, 'admin.second_factor.recovery_codes_rotated');

            return ['recovery_codes' => $codes];
        });

        if (isset($result['denied'])) {
            $this->audit($actor, 'admin.second_factor.recovery_rotation_denied');
            throw ValidationException::withMessages([
                $result['denied'] => 'Unable to verify account credentials.',
            ]);
        }

        return response()->json([
            'recovery_codes' => $result['recovery_codes'],
            'old_codes_invalidated' => true,
            'authenticator_changed' => false,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function resetAfterRecovery(Request $request)
    {
        $account = $this->account($request);
        abort_unless(
            AdminSecondFactorGate::recoveryResetAvailable($request),
            403,
            'A recently used recovery code is required to replace an authenticator.'
        );
        $input = $request->validate(['password' => 'required|string|max:128']);
        if (! Hash::check($input['password'], $account->password)) {
            $this->audit($account, 'admin.second_factor.recovery_reset_password_denied');
            throw ValidationException::withMessages(['password' => 'Unable to verify account credentials.']);
        }

        DB::transaction(function () use ($account, $request) {
            $lockedAccount = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedAccount->role === 'admin', 403);
            // Recheck the exact factor version under lock. A concurrent
            // replacement must never be deleted with an older recovery proof.
            $factor = DB::table('admin_second_factors')
                ->where('user_id', $account->id)->lockForUpdate()->first();
            $expected = $request->session()->get('admin_second_factor_recovery_enrollment_proof');
            abort_unless($factor && is_string($expected)
                && hash_equals(hash('sha256', $factor->encrypted_secret), $expected),
                409, 'Authenticator was changed. Restart recovery.');
            DB::table('admin_second_factor_recovery_codes')->where('user_id', $account->id)->delete();
            DB::table('admin_second_factors')->where('user_id', $account->id)->delete();
            $this->audit($account, 'admin.second_factor.reset_for_reenrollment');
        });
        $request->session()->forget([
            'admin_second_factor_user_id',
            'admin_second_factor_verified_at',
            'admin_second_factor_enrollment_proof',
            'admin_second_factor_pending',
            'admin_second_factor_recovery_user_id',
            'admin_second_factor_recovery_at',
            'admin_second_factor_recovery_enrollment_proof',
        ]);
        $request->session()->regenerate();

        return response()->json(['enrolled' => false, 'verified' => false])
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
        $request->session()->forget([
            'admin_second_factor_recovery_user_id',
            'admin_second_factor_recovery_at',
            'admin_second_factor_recovery_enrollment_proof',
        ]);
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
