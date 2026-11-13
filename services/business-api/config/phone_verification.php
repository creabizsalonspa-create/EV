<?php

return [
    'enabled' => env('PHONE_VERIFICATION_ENABLED', false),
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'api_key_sid' => env('TWILIO_VERIFY_API_KEY_SID'),
    'api_key_secret' => env('TWILIO_VERIFY_API_KEY_SECRET'),
    'service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
];
