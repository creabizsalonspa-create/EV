<?php

namespace App\Models\VendorOps;

use Illuminate\Database\Eloquent\Model;

class VendorCompanyReview extends Model
{
    protected $table = 'vendor_company_reviews';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Review history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Review history is append-only.'));
    }
}
