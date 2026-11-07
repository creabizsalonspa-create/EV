<?php

namespace App\Models\Charging;

use Illuminate\Database\Eloquent\Model;

class Evse extends Model
{
    protected $table = 'charging_evses';

    protected $guarded = ['id'];
}
