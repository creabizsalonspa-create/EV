<?php

use App\Models\VendorOps\VendorCharger;
use Illuminate\Support\Facades\DB;

ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$protocol = str_starts_with($run, '16') ? '1.6' : '2.0.1';
$identity = 'ev-test-diagnostics-'.$run;
VendorCharger::whereKey($f['charger_id'])->update(['ocpp_protocol' => $protocol]);
$bindingId = DB::table('csms_bindings')->insertGetId(['station_id' => $f['station_id'], 'vendor_charger_id' => $f['charger_id'], 'identifier' => $identity, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
echo json_encode([...$f, 'identity' => $identity, 'protocol' => $protocol, 'fixture_only' => true, 'binding_id' => $bindingId], JSON_THROW_ON_ERROR);
