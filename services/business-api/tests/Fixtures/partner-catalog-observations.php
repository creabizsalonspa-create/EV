<?php

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;

ob_start();
require __DIR__.'/canonical-topology.php'; // Strict isolated local SQLite/random-run guard.
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic catalog observations '.$run, 'connection_key' => 'catalog-observations-'.$run, 'authorized' => false]);
OcpiRecord::create(['ocpi_provider_id' => $provider->id, 'module' => 'locations', 'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'observations-'.$run, 'payload' => ['fixture_only' => true], 'normalized' => ['publish' => true, 'latitude' => '25', 'longitude' => '55', 'connector_standards' => []], 'content_hash' => hash('sha256', $run), 'source_updated_at' => now()->subDays(8), 'received_at' => now()]);
echo json_encode([...$f, 'provider_id' => $provider->id, 'provider_name' => $provider->name, 'owner_email' => $owner->email, 'customer_email' => $customer->email, 'fixture_only' => true], JSON_THROW_ON_ERROR);
