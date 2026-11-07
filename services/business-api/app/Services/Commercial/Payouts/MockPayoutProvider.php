<?php

namespace App\Services\Commercial\Payouts;

class MockPayoutProvider implements PayoutProvider
{
    public function verify(object $account): void
    {
        abort_unless(str_starts_with($account->account_reference, 'mock_') && str_starts_with($account->bank_reference, 'mock_'), 409, 'Mock payout account identifiers are required.');
    }

    public function balance(object $account): int
    {
        return 2000000000;
    }

    public function create(object $payout, object $account, object $attempt): array
    {
        return ['id' => 'mock_po_'.$attempt->reference, 'amount' => (int) $payout->amount_minor, 'currency' => $payout->currency, 'status' => config('payouts.mock_outcome'), 'attempt_reference' => $attempt->reference, 'failure_code' => config('payouts.mock_outcome') === 'failed' ? 'mock_failure' : null];
    }

    public function retrieve(object $payout, object $account, object $attempt): ?array
    {
        return $this->create($payout, $account, $attempt);
    }
}
