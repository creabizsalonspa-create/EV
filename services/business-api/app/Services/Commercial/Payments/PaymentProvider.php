<?php

namespace App\Services\Commercial\Payments;

interface PaymentProvider
{
    /** Tokens must be provider-owned opaque references, never PAN/CVV. */
    public function authorize(string $reference, int $amount, string $currency, string $token): array;

    public function capture(string $reference, int $amount, string $key): array;

    public function cancel(string $reference, string $key): array;

    public function refund(string $reference, int $amount, string $key): array;
}
