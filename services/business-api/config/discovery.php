<?php

return [
    // Enable only after approving the provider plan, privacy and attribution terms.
    'geocoding_enabled' => (bool) env('DISCOVERY_GEOCODING_ENABLED', false),
    'routing_enabled' => (bool) env('DISCOVERY_ROUTING_ENABLED', false),
    'geoapify_key' => env('DISCOVERY_GEOAPIFY_KEY'),
    // Licensed OCM discovery is not a charging network or remote operator.
    // Separate approved terms and server-only API key are mandatory.
    'ocm_enabled' => (bool) env('OCM_DISCOVERY_ENABLED', false),
    'ocm_terms_accepted' => (bool) env('OCM_DISCOVERY_TERMS_ACCEPTED', false),
    'ocm_api_key' => env('OCM_DISCOVERY_API_KEY'),
    'requests_per_minute' => (int) env('DISCOVERY_PROVIDER_REQUESTS_PER_MINUTE', 30),
    'requests_per_day' => (int) env('DISCOVERY_PROVIDER_REQUESTS_PER_DAY', 5000),
];
