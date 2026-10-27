<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;

abstract class ImmutableRecord extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        // Keep JSON and strict domain comparisons consistent across database drivers
        // and validated HTML inputs, which can contain numeric strings.
        return array_fill_keys([
            'id', 'station_id', 'created_by', 'charging_session_id', 'tariff_version_id',
            'user_id', 'vendor_id', 'quote_id', 'invoice_id', 'journal_id', 'actor_id',
            'minor_units', 'rate_minor_per_kwh', 'tax_bps', 'commission_bps', 'energy_wh',
            'subtotal_minor', 'tax_minor', 'commission_minor', 'vendor_minor', 'total_minor',
            'amount_minor', 'debit_minor', 'credit_minor',
        ], 'integer');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Finance records are immutable; append a correction.'));
        static::deleting(fn () => throw new \LogicException('Finance records are immutable; append a correction.'));
    }
}
