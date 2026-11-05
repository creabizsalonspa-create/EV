<?php

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;

ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$maps = [];
foreach ($f['connectors'] as $index => $connector) {
    $evse = Evse::create(['charger_id' => $f['charger_id'], 'number' => $index + 1, 'mode' => 'application']);
    $maps[] = ConnectorMapping::create(['station_id' => $f['station_id'], 'evse_id' => $evse->id, 'connector_id' => $connector])->id;
}
echo json_encode([...$f, 'maps' => $maps, 'fixture_only' => true], JSON_THROW_ON_ERROR);
