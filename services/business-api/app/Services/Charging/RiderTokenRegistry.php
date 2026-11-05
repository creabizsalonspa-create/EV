<?php

namespace App\Services\Charging;

use App\Models\Charging\RiderAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RiderTokenRegistry
{
    public function issue(User $actor, array $v): array
    {
        abort_unless($actor->role === 'customer', 403);
        $value = isset($v['value']) ? strtoupper($v['value']) : null;
        $expiry = CarbonImmutable::parse($v['expires_at'])->utc();
        $hash = hash('sha256', json_encode([$v['label'], $v['token_type'], $value, $expiry->toISOString()], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $v, $value, $expiry, $hash) {
                // Serialize this owner's request identities, including concurrent
                // generated-token requests whose random values differ per attempt.
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $old = RiderAccessToken::where('user_id', $actor->id)->where('idempotency_key', $v['idempotency_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Idempotency key payload mismatch.');

                    return ['token' => $old, 'created' => false];
                }
                abort_unless($expiry->isFuture() && $expiry->lessThanOrEqualTo(now()->addYear()), 422, 'Use an expiry within the next year.');
                $raw = $value ?? strtoupper(bin2hex(random_bytes(10)));
                abort_if(RiderAccessToken::where('token_digest', hash('sha256', $raw))->exists(), 409, 'Token value is unavailable.');
                $token = RiderAccessToken::create(['user_id' => $actor->id, 'label' => $v['label'], 'token_type' => $v['token_type'], 'token_ciphertext' => Crypt::encryptString($raw), 'token_digest' => hash('sha256', $raw), 'suffix' => substr($raw, -4), 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'expires_at' => $expiry, 'source_registration_nonce' => (string) Str::uuid()]);
                $this->event($token->id, null, $actor->id, 'token.registered');

                return ['token' => $token, 'value_once' => $raw, 'created' => true];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Token value or request identity is unavailable.');
        }
    }

    public function revoke(User $actor, RiderAccessToken $token): void
    {
        abort_unless($actor->id === $token->user_id && $actor->role === 'customer', 404);
        DB::transaction(function () use ($actor, $token) {
            $current = RiderAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
            if ($current->revoked_at !== null) {
                return;
            }
            $current->update(['revoked_at' => now()]);
            DB::table('rider_charging_grants')->where('token_id', $current->id)->whereNull('revoked_at')->update(['state' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
            $this->event($current->id, null, $actor->id, 'token.revoked');
        }, 3);
    }

    public function event(?int $token, ?int $grant, ?int $actor, string $action, ?string $note = null): void
    {
        DB::table('rider_authorization_events')->insert(['token_id' => $token, 'grant_id' => $grant, 'actor_id' => $actor, 'action' => $action, 'note' => $note === null ? null : Crypt::encryptString($note), 'created_at' => now()]);
    }
}
