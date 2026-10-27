<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorConnector extends Model
{
    protected $table = 'vendor_connectors';

    protected $guarded = ['id'];

    protected $attributes = ['maintenance_blocked' => false];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['maintenance_blocked' => 'boolean'];
    }
}
