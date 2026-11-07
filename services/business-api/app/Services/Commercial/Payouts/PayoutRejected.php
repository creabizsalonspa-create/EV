<?php

namespace App\Services\Commercial\Payouts;

class PayoutRejected extends \RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('The test payout provider rejected this request.');
    }
}
