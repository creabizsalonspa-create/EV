<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorSite extends Model
{
    protected $table = 'vendor_sites';

    protected $guarded = ['id'];

    protected $attributes = [];

    protected $hidden = [];

    protected function casts(): array
    {
        return [];
    }
}
