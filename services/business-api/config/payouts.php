<?php

return [
    'enabled' => env('PAYOUT_TEST_ENABLED', false),
    'stripe_webhook_secret' => env('PAYOUTS_STRIPE_TEST_WEBHOOK_SECRET', ''),
    'consent_version' => 'vendor-payout-v1',
    'mock_outcome' => 'paid',
];
