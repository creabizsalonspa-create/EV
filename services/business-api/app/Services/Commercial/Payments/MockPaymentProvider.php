<?php

namespace App\Services\Commercial\Payments;

/** Deterministic test PSP. No network requests or real financial instruments. */
class MockPaymentProvider implements PaymentProvider
{
    public function authorize(string $reference, int $amount, string $currency, string $token): array
    {
        abort_unless(in_array($token, ['mock_success', 'mock_decline'], true), 422, 'Use an explicitly mock provider token; card data is never accepted.');

        return ['status' => $token === 'mock_success' ? 'authorized' : 'failed', 'failure_code' => $token === 'mock_decline' ? 'mock_declined' : null];
    }

    public function capture(string $reference, int $amount, string $key): array
    {
        return ['reference' => 'mock_cap_'.hash('sha256', $reference.':'.$key), 'status' => 'captured'];
    }

    public function cancel(string $reference, string $key): array
    {
        return ['status' => 'cancelled'];
    }

    public function refund(string $reference, int $amount, string $key): array
    {
        return ['reference' => 'mock_ref_'.hash('sha256', $reference.':'.$key), 'status' => 'refunded'];
    }
}
