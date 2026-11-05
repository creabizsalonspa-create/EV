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
    $direction = $connection['registration_direction'] ?? 'outbound';
    if (! in_array($direction, ['outbound', 'incoming'], true) || ($direction === 'incoming' && ($connection['managed_credentials'] ?? false) !== true)) {
        throw new RuntimeException('Invalid managed OCPI registration direction.');
    }
    $tokenVariable = $connection['token_env'] ?? null;
    $inboundVariable = $connection['inbound_token_env'] ?? null;
    foreach ([$tokenVariable, $inboundVariable] as $variable) {
        if ($variable !== null && (! is_string($variable) || ! preg_match('/^OCPI_[A-Z0-9_]+_TOKEN$/D', $variable))) {
            throw new RuntimeException('OCPI credentials must reference OCPI_*_TOKEN server variables.');
        }
    }
    if (($direction === 'outbound' && $tokenVariable === null) || ($direction === 'incoming' && $inboundVariable === null)) {
        throw new RuntimeException('OCPI bootstrap credential variable is required.');
    }
    $connections[$key] = [
        'registration_direction' => $direction,
        'inbound_authorization_token' => $inboundVariable === null ? null : env($inboundVariable),
        'managed_credentials' => ($connection['managed_credentials'] ?? false) === true,
        'party_country' => $connection['party_country'] ?? null,
        'party_id' => $connection['party_id'] ?? null,
        'party_role' => $connection['party_role'] ?? null,
        'origin' => $connection['origin'] ?? null,
        'versions_path' => $connection['versions_path'] ?? '/ocpi/versions',
        'environment' => $connection['environment'] ?? 'unverified',
        'authorization_token' => $tokenVariable === null ? null : env($tokenVariable),
    ];
}

return ['local_credentials' => json_decode(env('OCPI_LOCAL_CREDENTIALS_JSON', '{}'), true, flags: JSON_THROW_ON_ERROR), 'connections' => $connections, 'max_pages' => 20, 'max_records' => 1000];
