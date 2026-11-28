<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class OcpiRecord extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['payload', 'content_hash'];

    /** Only an explicit JSON boolean true permits public location projection. */
    public function scopeExplicitlyPublished(Builder $query): Builder
    {
        // SQLite's json_extract converts true and numeric 1 to the same
        // SQL value. PostgreSQL text extraction similarly loses JSON type.
        // Preserve type before pagination, including legacy corrupt rows.
        return match ($query->getModel()->getConnection()->getDriverName()) {
            'sqlite' => $query->whereRaw("json_type(ocpi_records.normalized, '$.publish') = 'true'"),
            'pgsql' => $query->whereRaw("(ocpi_records.normalized::jsonb->'publish') = 'true'::jsonb"),
            default => throw new \LogicException('Unsupported partner publication database.'),
        };
    }

    protected function casts(): array
    {
        return ['normalized' => 'array', 'source_updated_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    /** The digest distinguishes JSON 1.0 from 1. Keep the encrypted JSON
     * representation consistent with the canonical digest on new writes. */
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR),
            set: fn ($value) => $value === null ? null : Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
    }

    /**
     * OCPI instants are UTC even on a non-UTC PHP host. Raw SQL DATETIME
     * columns have no timezone; the default immutable_datetime cast would
     * decode the same stored instant using the host's current timezone.
     * Persist and retrieve both authoritative and local receipt instants in
     * UTC before applying freshness, ordering, and reservation policy.
     */
    private function utcSourceTimestamp(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC'),
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()),
        );
    }

    protected function sourceUpdatedAt(): Attribute
    {
        return $this->utcSourceTimestamp();
    }

    protected function receivedAt(): Attribute
    {
        return $this->utcSourceTimestamp();
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
