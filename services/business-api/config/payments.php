<?php

return [
    // Mock and explicitly configured Stripe test contracts only; live money is disabled.
    'hosted_return_url' => env('PAYMENT_HOSTED_RETURN_URL'),
    'mandate_consent_version' => 'recurring-v1',
    'mandate_consent_days' => 365,
    'collection_enabled' => env('SUBSCRIPTION_COLLECTION_ENABLED', false),
    'collection_retry_days' => [1, 3, 7],
    'recovery_provider_poll' => env('PAYMENT_RECOVERY_PROVIDER_POLL', false),
    'provider' => env('PAYMENT_PROVIDER', 'mock'),
    'webhook_secret' => env('PAYMENT_MOCK_WEBHOOK_SECRET'),
    'authorization_minutes' => 30,
    'stripe_test_secret' => env('STRIPE_TEST_SECRET'),
    'stripe_test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
];
