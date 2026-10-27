<?php

return [
    // Mock is the sole enabled adapter. Live money movement is intentionally unavailable.
    'recovery_provider_poll' => env('PAYMENT_RECOVERY_PROVIDER_POLL', false),
    'provider' => env('PAYMENT_PROVIDER', 'mock'),
    'webhook_secret' => env('PAYMENT_MOCK_WEBHOOK_SECRET'),
    'authorization_minutes' => 30,
    'stripe_test_secret' => env('STRIPE_TEST_SECRET'),
    'stripe_test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
];
