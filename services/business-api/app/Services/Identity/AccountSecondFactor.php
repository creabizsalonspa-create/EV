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
        if (preg_match('/^(?:[0-9A-F]{6}(?:-[0-9A-F]{6}){3}|[0-9A-F]{8}(?:-[0-9A-F]{8}){3})$/D', $code)) {
            $stored = $this->recoverySet($factor->recovery_hashes);
            $hashes = $stored['hashes'];
            $keys = array_values(array_unique(array_merge([(string) config('app.key')], array_filter((array) config('app.previous_keys', []), 'is_string'))));
            $digests = $stored['version'] === 2
                ? [hash('sha256', "creabiz-account-recovery-v2\n".$code)]
                : array_map(fn ($key) => hash_hmac('sha256', $code, $key), $keys);
            $match = null;
            foreach ($hashes as $index => $hash) {
                foreach ($digests as $digest) {
                    if (hash_equals($hash, $digest)) {
                        $match = $index;
                    }
                }
            }
            if ($match !== null) {
                unset($hashes[$match]);
                DB::table('account_second_factors')->where('user_id', $user->id)->update([
                    'recovery_hashes' => $this->sealRecovery($stored['version'], array_values($hashes)), 'updated_at' => now(),
                ]);

                DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => 'account.second_factor.recovery_used', 'subject' => (string) $user->id, 'created_at' => now()]);

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
            $codes[] = implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
        }

        return $codes;
    }

    public function recoverySet(?string $encrypted): array
    {
        if ($encrypted === null) {
            return ['version' => 2, 'hashes' => []];
        }
        $data = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        if (is_array($data) && array_is_list($data)) {
            $version = 1;
            $hashes = $data;
        } else {
            abort_unless(is_array($data) && ($data['version'] ?? null) === 2 && array_key_exists('hashes', $data) && count($data) === 2, 409, 'Authenticator recovery data requires review.');
            $version = 2;
            $hashes = $data['hashes'];
        }
        abort_unless(is_array($hashes) && array_is_list($hashes) && count($hashes) <= 8, 409, 'Authenticator recovery data requires review.');
        foreach ($hashes as $hash) {
            abort_unless(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash), 409, 'Authenticator recovery data requires review.');
        }
        abort_unless(count(array_unique($hashes)) === count($hashes), 409, 'Authenticator recovery data requires review.');

        return compact('version', 'hashes');
    }

    private function sealRecovery(int $version, array $hashes): string
    {
        return Crypt::encryptString(json_encode($version === 2 ? ['version' => 2, 'hashes' => $hashes] : $hashes, JSON_THROW_ON_ERROR));
    }

    public function encryptedHashes(array $codes): string
    {
        // These independent 128-bit random codes do not need an application-key
        // HMAC; encrypted, domain-separated hashes survive approved key rollover.
        return $this->sealRecovery(2, array_map(fn ($code) => hash('sha256', "creabiz-account-recovery-v2\n".$code), $codes));
    }

    public function rotateAuthority(User $user): void
    {
        $user->forceFill(['auth_epoch' => (int) $user->auth_epoch + 1, 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
