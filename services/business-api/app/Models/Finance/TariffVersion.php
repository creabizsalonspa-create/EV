<?php

namespace App\Models\Finance;

class TariffVersion extends ImmutableRecord
{
    protected $table = 'finance_tariff_versions';

    protected $hidden = ['publishing_key', 'publishing_fingerprint'];
}
