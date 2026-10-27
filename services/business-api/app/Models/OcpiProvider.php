<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcpiProvider extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['connection_key', 'endpoints'];

    protected $attributes = ['authorized' => false, 'evidence' => 'UNVERIFIED'];

    protected function casts(): array
    {
        return ['authorized' => 'boolean', 'endpoints' => 'array', 'negotiated_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }
}
