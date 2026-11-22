<?php

return [
    'enabled' => env('LOYALTY_TEST_ENABLED', false),
    // Default deny. These are operator-reviewed deployment inputs, not an
    // assertion of legal endorsement or physical fulfillment verification.
    'referrals' => ['verified_live_source_ready' => env('LOYALTY_REFERRAL_VERIFIED_LIVE_SOURCE_READY', false)],
    'noncash' => [
        'mode' => env('LOYALTY_NONCASH_MODE', 'disabled'),
        'approval_reference' => env('LOYALTY_NONCASH_APPROVAL_REFERENCE', ''),
        'terms_sha256' => env('LOYALTY_NONCASH_TERMS_SHA256', ''),
        'fulfillment_reviewed' => env('LOYALTY_NONCASH_FULFILLMENT_REVIEWED', false),
    ],
];
