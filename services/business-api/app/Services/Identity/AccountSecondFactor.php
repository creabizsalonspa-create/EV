<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Security\AdminTotp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountSecondFactor
{
    public function account(User $actor): User
    {
        abort_unless(in_array($actor->role, ['customer', 'vendor'], true), 403);
        $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->account_status === 'active', 403, 'Account access is unavailable.');
        abort_unless(in_array($user->role, ['customer', 'vendor'], true) && $user->role === $actor->role
            && (int) $user->auth_epoch === (int) $actor->auth_epoch
            && hash_equals((string) $user->password, (string) $actor->password), 409, 'Account credentials changed; sign in again.');

        return $user;
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['second_factor_code' => 'Enter a valid authenticator or recovery code.']);
    }

    /** The user row is locked before the factor row on every read/consume/write. */
    public function consume(User $user, string $code): void
    {
        $factor = DB::table('account_second_factors')->where('user_id', $user->id)->lockForUpdate()->first();
        if (! $factor || $factor->secret === null) {
            return;
        }
        $code = strtoupper(trim($code));
        $counter = AdminTotp::matchingStep(Crypt::decryptString($factor->secret), $code, now()->timestamp,
            $factor->last_counter === null ? null : (int) $factor->last_counter);
        if ($counter !== null) {
            DB::table('account_second_factors')->where('user_id', $user->id)->update(['last_counter' => $counter, 'updated_at' => now()]);

            return;
        }
        if (preg_match('/^[0-9A-F]{6}(?:-[0-9A-F]{6}){3}$/D', $code)) {
            $hashes = json_decode(Crypt::decryptString($factor->recovery_hashes), true, 512, JSON_THROW_ON_ERROR);
            $digest = hash_hmac('sha256', $code, config('app.key'));
            $match = null;
            foreach ($hashes as $index => $hash) {
                if (hash_equals($hash, $digest)) {
                    $match = $index;
                }
            }
            if ($match !== null) {
                unset($hashes[$match]);
                DB::table('account_second_factors')->where('user_id', $user->id)->update([
                    'recovery_hashes' => Crypt::encryptString(json_encode(array_values($hashes), JSON_THROW_ON_ERROR)), 'updated_at' => now(),
                ]);

                return;
            }
        }
        $this->deny();
    }

    public function authenticate(User $actor, string $code): User
    {
        return DB::transaction(function () use ($actor, $code) {
            $user = $this->account($actor);
            $this->consume($user, $code);

            return $user;
        });
    }

    public function recoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = implode('-', str_split(strtoupper(bin2hex(random_bytes(12))), 6));
        }

        return $codes;
    }

    public function encryptedHashes(array $codes): string
    {
        return Crypt::encryptString(json_encode(array_map(fn ($code) => hash_hmac('sha256', $code, config('app.key')), $codes), JSON_THROW_ON_ERROR));
    }

    public function rotateAuthority(User $user): void
    {
        $user->forceFill(['auth_epoch' => (int) $user->auth_epoch + 1, 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
