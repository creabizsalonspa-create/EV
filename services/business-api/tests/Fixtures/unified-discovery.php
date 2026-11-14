<?php

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;

ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic unified '.$run, 'connection_key' => 'unified-fixture-'.$run, 'authorized' => true, 'version' => '2.2.1']);
$record = OcpiRecord::create(['ocpi_provider_id' => $provider->id, 'module' => 'locations', 'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'unified-'.$run, 'payload' => ['fixture_only' => true], 'content_hash' => hash('sha256', $run), 'normalized' => ['publish' => true, 'name' => '<b>Partner '.$run.'</b>', 'address' => 'Synthetic address', 'country' => 'ARE', 'evses' => [], 'connector_standards' => ['IEC_62196_T2_COMBO']], 'source_updated_at' => now(), 'received_at' => now()]);
echo json_encode([...$f, 'record_id' => $record->id, 'fixture_only' => true], JSON_THROW_ON_ERROR);
