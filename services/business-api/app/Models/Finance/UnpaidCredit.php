<?php

namespace App\Models\Finance;

class UnpaidCredit extends ImmutableRecord
{
    protected $table = 'finance_unpaid_credits';

    protected $hidden = ['payload_hash', 'credit_source_hash'];

    protected function casts(): array
    {
        return parent::casts() + ['credit_record_id' => 'integer', 'reason' => 'encrypted'];
    }
}
