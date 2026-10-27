<?php

// Metadata contains only approved origins and credential variable NAMES. Values must
// be securely injected. The API cannot provision endpoints or read credential values.
$metadata = json_decode(env('OCPI_CONNECTIONS_JSON', '{}'), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($metadata)) {
    throw new RuntimeException('OCPI_CONNECTIONS_JSON must be a connection metadata object.');
}
$connections = [];
foreach ($metadata as $key => $connection) {
    if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{1,80}$/', $key) || ! is_array($connection)) {
        throw new RuntimeException('Invalid OCPI connection metadata.');
    }
    $tokenVariable = $connection['token_env'] ?? '';
    if (! is_string($tokenVariable) || ! preg_match('/^OCPI_[A-Z0-9_]+_TOKEN$/', $tokenVariable)) {
        throw new RuntimeException('OCPI token_env must name an OCPI_*_TOKEN variable.');
    }
    $connections[$key] = [
        'origin' => $connection['origin'] ?? null,
        'versions_path' => $connection['versions_path'] ?? '/ocpi/versions',
        'environment' => $connection['environment'] ?? 'unverified',
        'authorization_token' => env($tokenVariable),
    ];
}

return ['connections' => $connections, 'max_pages' => 20, 'max_records' => 1000];
