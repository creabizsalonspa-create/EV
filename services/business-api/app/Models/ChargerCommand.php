<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargerCommand extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'confirmation' => 'array'];
    }
}
