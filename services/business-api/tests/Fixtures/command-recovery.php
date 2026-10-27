<?php

use App\Models\ChargerCommand;
use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SessionBridge;
use Illuminate\Support\Facades\DB;

ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$customer = User::where('email', 'canonical-customer-'.$run.'@example.test')->firstOrFail();
VendorCharger::whereKey($f['charger_id'])->update(['ocpp_protocol' => '1.6']);
$bindingId = DB::table('csms_bindings')->insertGetId(['vendor_charger_id' => $f['charger_id'], 'station_id' => $f['station_id'], 'identifier' => 'ev-test-recovery-'.$run, 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
$evse = Evse::create(['charger_id' => $f['charger_id'], 'number' => 1, 'mode' => 'csms', 'binding_id' => $bindingId, 'source_evse_number' => 0]);
$map = ConnectorMapping::create(['station_id' => $f['station_id'], 'evse_id' => $evse->id, 'connector_id' => $f['connectors'][0], 'source_connector_number' => 1, 'source_target_key' => hash('sha256', $bindingId.':0:1')]);
$r = Reservation::create(['connector_mapping_id' => $map->id, 'charging_evse_id' => $evse->id, 'station_id' => $f['station_id'], 'user_id' => $customer->id, 'starts_at' => now()->subMinutes(5)->startOfSecond(), 'idempotency_key' => 'recovery-'.$run]);
$bridge = app(SessionBridge::class);
$queued = $bridge->start($r, $customer->id);
ChargerCommand::whereKey($queued['start_command']['id'])->update(['status' => 'outcome_unknown', 'failure_code' => 'dispatch_requires_reconciliation']);
// Synthetic fixture models a process interruption after authenticated inbox commit.
// It is not a real source observation or hardware/CSMS acceptance test.
$app->instance(CommandReconciler::class, new class extends CommandReconciler
{
    public function forSession(int $sessionId): int
    {
        return 0;
    }
});
$bridge->ingest(['event_id' => 'synthetic-recovery-'.$run, 'session_id' => $queued['session']['id'], 'identifier' => 'ev-test-recovery-'.$run, 'protocol' => '1.6', 'transaction_id' => '123', 'source_evse_number' => 0, 'source_connector_number' => 1, 'type' => 'started', 'sequence' => 0, 'meter_wh' => 1000, 'occurred_at' => now()->toISOString()]);
echo json_encode([...$f, 'session_id' => $queued['session']['id'], 'fixture_only' => true], JSON_THROW_ON_ERROR);
