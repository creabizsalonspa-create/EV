<?php

namespace App\Models\Charging;

use Illuminate\Database\Eloquent\Model;

class OperationalCase extends Model
{
    protected $table = 'charging_operational_cases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['operator_note' => 'encrypted'];
    }
}
