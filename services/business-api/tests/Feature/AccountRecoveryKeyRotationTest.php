<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\AdminTotp;
use App\Services\Identity\AccountSecondFactor;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountRecoveryKeyRotationTest extends TestCase
{
    use RefreshDatabase;

    private function setupRecovery(bool $legacy): array
    {
        $service = app(AccountSecondFactor::class);
        $user = User::factory()->create();
        $codes = $legacy ? array_map(fn () => implode('-', str_split(strtoupper(bin2hex(random_bytes(12))), 6)), range(1, 8)) : $service->recoveryCodes();
        $secret = AdminTotp::generateSecret();
        $hashes = $legacy ? Crypt::encryptString(json_encode(array_map(fn ($code) => hash_hmac('sha256', $code, config('app.key')), $codes))) : $service->encryptedHashes($codes);
        DB::table('account_second_factors')->insert(['user_id' => $user->id, 'secret' => Crypt::encryptString($secret), 'recovery_hashes' => $hashes, 'created_at' => now(), 'updated_at' => now()]);
        $original = Crypt::getFacadeRoot();
        $old = config('app.key');
        $new = random_bytes(32);
        $encrypter = new Encrypter($new, 'AES-256-CBC');
        $encrypter->previousKeys([str_starts_with($old, 'base64:') ? base64_decode(substr($old, 7)) : $old]);
        Crypt::swap($encrypter);
        config(['app.key' => 'base64:'.base64_encode($new), 'app.previous_keys' => [$old]]);
        $this->beforeApplicationDestroyed(fn () => Crypt::swap($original));

        return [$service, $user, $codes, $secret, $encrypter];
    }

    public function test_new_recovery_codes_survive_rewrapping_and_old_key_retirement_and_remain_single_use(): void
    {
        [$service, $user, $codes, $secret, $encrypter] = $this->setupRecovery(false);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{8}(?:-[A-F0-9]{8}){3}$/D', $codes[0]);
        $service->authenticate($user, $codes[0]);
        DB::table('account_second_factors')->where('user_id', $user->id)->update(['secret' => Crypt::encryptString($secret)]);
        $encrypter->previousKeys([]);
        config(['app.previous_keys' => []]);
        $service->authenticate($user, $codes[1]);
        $this->actingAs($user)->getJson('/api/account/second-factor')->assertOk()->assertJsonPath('recovery_remaining', 6)->assertJsonPath('recovery_rotation_recommended', false);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'account.second_factor.recovery_used')->count());
        $this->expectException(ValidationException::class);
        $service->authenticate($user, $codes[0]);
    }

    public function test_legacy_codes_accept_only_explicit_previous_keys_and_recommend_regeneration(): void
    {
        [$service, $user, $codes] = $this->setupRecovery(true);
        $service->authenticate($user, $codes[0]);
        $this->actingAs($user)->getJson('/api/account/second-factor')->assertOk()->assertJsonPath('recovery_remaining', 7)->assertJsonPath('recovery_rotation_recommended', true);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'account.second_factor.recovery_used')->count());
        $this->expectException(ValidationException::class);
        $service->authenticate($user, $codes[0]);
    }

    public function test_unapproved_retired_legacy_key_cannot_validate_a_rewrapped_recovery_record(): void
    {
        [$service, $user, $codes, $secret, $encrypter] = $this->setupRecovery(true);
        $row = DB::table('account_second_factors')->where('user_id', $user->id)->first();
        $plaintext = Crypt::decryptString($row->recovery_hashes);
        DB::table('account_second_factors')->where('user_id', $user->id)->update(['secret' => Crypt::encryptString($secret), 'recovery_hashes' => Crypt::encryptString($plaintext)]);
        $encrypter->previousKeys([]);
        config(['app.previous_keys' => []]);
        $this->expectException(ValidationException::class);
        $service->authenticate($user, $codes[0]);
    }
}
