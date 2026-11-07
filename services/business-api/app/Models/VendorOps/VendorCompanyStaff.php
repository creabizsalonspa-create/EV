<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorCompanyStaff extends Model
{
    protected $table = 'vendor_company_staff';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'invited', 'invitation_version' => 1];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'invitation_version' => 'integer', 'accepted_at' => 'immutable_datetime'];
    }
}
