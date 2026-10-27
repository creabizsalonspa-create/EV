<?php

return [
    // This development adapter deliberately cannot address physical infrastructure.
    'session_bridge_enabled' => env('CSMS_SESSION_BRIDGE_ENABLED', false),
    'event_secret' => env('CSMS_EVENT_SECRET'),
    'simulator_enabled' => env('CSMS_SIMULATOR_ENABLED', false),
    'base_url' => 'http://127.0.0.1:8080',
];
