<?php

return [
    // This development adapter deliberately cannot address physical infrastructure.
    'simulator_enabled' => env('CSMS_SIMULATOR_ENABLED', false),
    'base_url' => 'http://127.0.0.1:8080',
];
