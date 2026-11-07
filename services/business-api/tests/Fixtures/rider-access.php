<?php

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use Illuminate\Support\Facades\DB;

ob_start();
require __DIR__.'/charger-diagnostics.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$binding = DB::table('csms_bindings')->where('identifier', $f['identity'])->first();
$evse = Evse::create(['charger_id' => $f['charger_id'], 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding->id, 'source_evse_number' => $f['protocol'] === '1.6' ? 0 : 1]);
$map = ConnectorMapping::create(['station_id' => $f['station_id'], 'evse_id' => $evse->id, 'connector_id' => $f['connectors'][0], 'source_connector_number' => 1]);
echo json_encode([...$f, 'mapping_id' => $map->id], JSON_THROW_ON_ERROR);
