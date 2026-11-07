<?php

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Services\Charging\SessionBridge;
use Illuminate\Support\Facades\DB;

ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$identity = 'ev-test-operations-'.$run;
VendorCharger::whereKey($f['charger_id'])->update(['ocpp_protocol' => '2.0.1']);
$binding = DB::table('csms_bindings')->insertGetId(['station_id' => $f['station_id'], 'vendor_charger_id' => $f['charger_id'], 'identifier' => $identity, 'protocol' => '2.0.1', 'created_at' => now(), 'updated_at' => now()]);
$evse = Evse::create(['charger_id' => $f['charger_id'], 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding, 'source_evse_number' => 5]);
$map = ConnectorMapping::create(['station_id' => $f['station_id'], 'evse_id' => $evse->id, 'connector_id' => $f['connectors'][0], 'source_connector_number' => 7, 'source_target_key' => hash('sha256', $binding.':5:7')]);
$customer = User::where('email', 'canonical-customer-'.$run.'@example.test')->firstOrFail();
$reservation = Reservation::create(['station_id' => $f['station_id'], 'user_id' => $customer->id, 'connector_mapping_id' => $map->id, 'charging_evse_id' => $evse->id, 'starts_at' => now()->subMinutes(5)->startOfSecond(), 'idempotency_key' => 'operations-'.$run]);
// CLI-only synthetic uncertain request. No dispatch, source event or payment occurs.
config(['csms.session_bridge_enabled' => true]);
$queued = app(SessionBridge::class)->start($reservation, $customer->id);
ChargerCommand::whereKey($queued['start_command']['id'])->update(['status' => 'outcome_unknown', 'failure_code' => 'dispatch_requires_reconciliation']);
echo json_encode([...$f, 'identity' => $identity, 'command_id' => $queued['start_command']['id'], 'session_id' => $queued['session']['id'], 'fixture_only' => true], JSON_THROW_ON_ERROR);
