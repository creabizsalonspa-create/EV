<?php

namespace App\Services\Commercial\Payouts;

interface PayoutProvider
{
    public function verify(object $account): void;

    public function balance(object $account): int;

    public function create(object $payout, object $account, object $attempt): array;

    public function retrieve(object $payout, object $account, object $attempt): ?array;
}
