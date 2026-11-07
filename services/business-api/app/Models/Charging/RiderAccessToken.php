<?php

namespace App\Models\Charging;

use Illuminate\Database\Eloquent\Model;

class RiderAccessToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_ciphertext', 'token_digest', 'payload_hash', 'idempotency_key', 'source_registration_nonce', 'source_authorization_id', 'provision_lease', 'provision_lease_until'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'provisioned_at' => 'immutable_datetime', 'provision_lease_until' => 'immutable_datetime'];
    }
}
