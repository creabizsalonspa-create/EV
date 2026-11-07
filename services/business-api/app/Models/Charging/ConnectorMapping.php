<?php

namespace App\Models\Charging;

use Illuminate\Database\Eloquent\Model;

class ConnectorMapping extends Model
{
    protected $table = 'charging_connector_mappings';

    protected $guarded = ['id'];

    protected $hidden = ['source_target_key'];
}
