<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcpiRecord extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload', 'content_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'normalized' => 'array', 'source_updated_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if ($record->getOriginal('module') === 'cdrs') {
                throw new \LogicException('An accepted OCPI CDR is immutable.');
            }
        });
        static::deleting(function (self $record) {
            if ($record->module === 'cdrs') {
                throw new \LogicException('An accepted OCPI CDR cannot be deleted.');
            }
        });
    }
}
