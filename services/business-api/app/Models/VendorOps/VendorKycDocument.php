<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorKycDocument extends Model
{
    protected $table = 'vendor_kyc_documents';

    protected $guarded = ['id'];

    protected $attributes = [];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [];
    }
}
