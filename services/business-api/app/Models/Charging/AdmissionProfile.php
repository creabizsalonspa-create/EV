<?php

namespace App\Models\Charging;

use Illuminate\Database\Eloquent\Model;

class AdmissionProfile extends Model
{
    protected $table = 'charger_admission_profiles';

    protected $guarded = ['id'];

    protected $attributes = ['enabled' => false, 'credential_version' => 0];

    protected $hidden = ['credential_digest'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'credential_version' => 'integer', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
