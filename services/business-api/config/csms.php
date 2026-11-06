<?php

return [
    'configuration_read_enabled' => env('CSMS_CONFIGURATION_READ_ENABLED', false),
    'fault_worker_enabled' => env('CSMS_FAULT_WORKER_ENABLED', false),
    'firmware_enabled' => env('CSMS_SIGNED_FIRMWARE_ENABLED', false),
    'firmware_catalog_root' => storage_path('app/private/firmware/catalog'),
    'firmware_signer_pins' => array_values(array_filter(explode(',', (string) env('CSMS_FIRMWARE_SIGNER_PINS', '')))),
    'firmware_download_base' => env('CSMS_FIRMWARE_DOWNLOAD_BASE', 'http://127.0.0.1:8000'),
    'remote_authorization_enabled' => env('CSMS_REMOTE_AUTHORIZATION_ENABLED', false),
    'command_worker_enabled' => env('CSMS_COMMAND_WORKER_ENABLED', false),
    'operations_base_url' => env('CSMS_OPERATIONS_BASE_URL', 'http://citrine:8080'),
    'safe_reset_enabled' => env('CSMS_SAFE_RESET_ENABLED', false),
    'rider_enabled' => env('CSMS_RIDER_AUTH_ENABLED', false),
    'rider_client_id' => env('CSMS_RIDER_CLIENT_ID', 'creabiz-csms-rider'),
    'rider_client_secret' => env('CSMS_RIDER_CLIENT_SECRET'),
    'rider_callback_url' => env('CSMS_RIDER_CALLBACK_URL'),
    'rider_source_api_url' => env('CSMS_RIDER_SOURCE_API_URL', 'http://citrine:8080'),
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
