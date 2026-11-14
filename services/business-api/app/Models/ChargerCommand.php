<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargerCommand extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['consent_auth_epoch'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['consent_auth_epoch' => 'integer', 'payload' => 'array', 'confirmation' => 'array'];
    }
}
