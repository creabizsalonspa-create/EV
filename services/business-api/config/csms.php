<?php

return [
    'ingress_allow_local_http' => env('CSMS_INGRESS_ALLOW_LOCAL_HTTP', false),
    'ingress_enabled' => env('CSMS_INGRESS_AUTH_ENABLED', false),
    'ingress_secret' => env('CSMS_INGRESS_AUTH_SECRET'),
    // This development adapter deliberately cannot address physical infrastructure.
    'session_bridge_enabled' => env('CSMS_SESSION_BRIDGE_ENABLED', false),
    'graphql_url' => env('CSMS_GRAPHQL_URL', 'http://127.0.0.1:8095/v1/graphql'),
    'graphql_secret' => env('CSMS_GRAPHQL_SECRET'),
    'event_secret' => env('CSMS_EVENT_SECRET'),
    'simulator_enabled' => env('CSMS_SIMULATOR_ENABLED', false),
    'base_url' => 'http://127.0.0.1:8080',
];
