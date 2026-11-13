<?php

return [
    'enabled' => env('PHONE_VERIFICATION_ENABLED', false),
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
];
