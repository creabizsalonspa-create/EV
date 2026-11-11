<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorKycDocument extends Model
{
    protected $table = 'vendor_kyc_documents';

    protected $guarded = ['id'];

    protected $attributes = [];

    // Evidence fingerprints and storage locators stay server-side. Even an
    // authorized metadata listing should not expose a reusable private
    // document identifier or facilitate cross-tenant digest correlation.
    // The server still uses $document->sha256 for integrity checks.
    protected $hidden = ['storage_path', 'sha256'];

    protected function casts(): array
    {
        return [];
    }
}
