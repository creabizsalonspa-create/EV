<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcpiProvider extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['connection_key', 'endpoints', 'authorization_revision'];

    protected $attributes = ['authorized' => false, 'evidence' => 'UNVERIFIED', 'authorization_revision' => 0];

    protected function casts(): array
    {
        return ['authorization_revision' => 'integer', 'authorized' => 'boolean', 'endpoints' => 'array', 'negotiated_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }
}
