<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorCompany extends Model
{
    protected $table = 'vendor_companies';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['trade_license_number', 'review_reason'];

    protected function casts(): array
    {
        return ['trade_license_number' => 'encrypted', 'license_expires_on' => 'date', 'reviewed_at' => 'datetime'];
    }
}
