<?php

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Charging\FaultMonitor;
use Illuminate\Support\Facades\DB;

ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic unified '.$run, 'connection_key' => 'unified-fixture-'.$run, 'authorized' => true, 'version' => '2.2.1']);
$record = OcpiRecord::create(['ocpi_provider_id' => $provider->id, 'module' => 'locations', 'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'unified-'.$run, 'payload' => ['fixture_only' => true], 'content_hash' => hash('sha256', $run), 'normalized' => ['publish' => true, 'name' => '<b>Partner '.$run.'</b>', 'address' => 'Synthetic address', 'country' => 'ARE', 'latitude' => '25', 'longitude' => '55', 'evses' => [], 'connector_standards' => ['IEC_62196_T2_COMBO']], 'source_updated_at' => now(), 'received_at' => now()]);
$charger->update(['ocpp_protocol' => '2.0.1']);
$bindingId = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'ev-test-unified-'.$run, 'protocol' => '2.0.1', 'created_at' => now(), 'updated_at' => now()]);
$evse = Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $bindingId, 'source_evse_number' => 1]);
ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connectors[0], 'source_connector_number' => 1]);
$binding = DB::table('csms_bindings')->find($bindingId);
DB::table('charging_status_observations')->insert(['binding_id' => $bindingId, 'source_generation' => FaultMonitor::generation($binding), 'source_event_id' => 1, 'fingerprint' => hash('sha256', 'unified-report-'.$run), 'evse_number' => 1, 'connector_number' => 1, 'status' => 'Available', 'error_code' => 'NoError', 'occurred_at' => now(), 'created_at' => now()]);
echo json_encode([...$f, 'record_id' => $record->id, 'fixture_only' => true], JSON_THROW_ON_ERROR);
