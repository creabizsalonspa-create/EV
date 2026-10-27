<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorCharger extends Model
{
    protected $table = 'vendor_chargers';

    protected $guarded = ['id'];

    protected $attributes = ['integration_status' => 'unverified'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [];
    }
}
