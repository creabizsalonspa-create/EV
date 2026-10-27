<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargingSession extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'charging', 'energy_wh' => 0, 'total_minor' => null, 'stopped_at' => null];
}
