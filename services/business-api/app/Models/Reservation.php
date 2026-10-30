<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'confirmed'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'immutable_datetime'];
    }
}
