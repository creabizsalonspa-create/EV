<?php
// Executed over stdin ONLY inside the disposable staging recovery application.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if (app()->environment() !== 'staging' || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'ev_recovery_review'
    || !preg_match('/^ev-recovery-[0-9]+-[0-9]+-db-(source|target)$/D', config('database.connections.pgsql.host'))
    || config('csms.command_worker_enabled') || config('csms.session_bridge_enabled')) {
    throw new RuntimeException('Domain recovery fixture requires the isolated disabled-worker runtime.');
}
$action = $argv[1] ?? '';
if ($action === 'seed') {
    DB::transaction(function () {
        foreach (['users', 'stations', 'reservations', 'vehicles', 'charging_sessions', 'charger_commands', 'csms_session_links', 'csms_inbox_events'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Domain fixture refuses a populated application database.');
            }
        }
        $vendor = \App\Models\User::forceCreate(['name' => 'Synthetic recovery vendor', 'email' => 'recovery-vendor@example.invalid', 'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), 'role' => 'vendor']);
        $customer = \App\Models\User::forceCreate(['name' => 'Synthetic recovery customer', 'email' => 'recovery-customer@example.invalid', 'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), 'role' => 'customer']);
        $vehicle = \App\Models\Vehicle::create(['user_id' => $customer->id, 'name' => 'Synthetic recovery vehicle', 'connector_type' => 'CCS2']);
        $company = \App\Models\VendorOps\VendorCompany::create(['owner_id' => $vendor->id, 'legal_name' => 'Synthetic recovery company', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-ONLY', 'license_expires_on' => '2030-01-01', 'status' => 'approved']);
        foreach (['1.6', '2.0.1'] as $index => $protocol) {
            $station = \App\Models\Station::create(['vendor_id' => $vendor->id, 'name' => 'Synthetic recovery '.$protocol, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'discovery', 'connector_type' => 'CCS2', 'power_kw' => 50]);
            $site = \App\Models\VendorOps\VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Synthetic site '.$protocol, 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic recovery only', 'latitude' => 25, 'longitude' => 55]);
            $charger = \App\Models\VendorOps\VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'SYNTHETIC-'.$protocol, 'manufacturer' => 'Synthetic', 'model' => 'Recovery fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
            $connector = \App\Models\VendorOps\VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
            $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'vendor_charger_id' => $charger->id, 'identifier' => 'recovery-no-endpoint-'.$index, 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
            $evse = \App\Models\Charging\Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'csms', 'binding_id' => $binding, 'source_evse_number' => $protocol === '1.6' ? 0 : 1]);
            $mapping = \App\Models\Charging\ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id, 'source_connector_number' => 1, 'source_target_key' => hash('sha256', 'recovery-'.$protocol)]);
            $reservation = \App\Models\Reservation::create(['user_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'station_id' => $station->id, 'connector_mapping_id' => $mapping->id, 'charging_evse_id' => $evse->id, 'starts_at' => '2026-01-01 00:00:00', 'status' => 'cancelled', 'idempotency_key' => 'recovery-'.$protocol]);
            $session = \App\Models\ChargingSession::create(['reservation_id' => $reservation->id, 'charging_evse_id' => $evse->id, 'status' => 'stopped', 'energy_wh' => 250, 'rate_minor_per_kwh' => 0, 'currency' => 'AED', 'stopped_at' => '2026-01-01 00:01:00']);
            $command = \App\Models\ChargerCommand::create(['actor_id' => $vendor->id, 'station_id' => $station->id, 'idempotency_key' => 'recovery-'.$protocol, 'payload_hash' => hash('sha256', 'synthetic-only'), 'action' => 'start', 'status' => 'failed', 'payload' => ['id_token' => 'synthetic-recovery-token']]);
            $link = DB::table('csms_session_links')->insertGetId(['charging_session_id' => $session->id, 'binding_id' => $binding, 'start_command_id' => $command->id, 'connector_mapping_id' => $mapping->id, 'identifier' => 'recovery-no-endpoint-'.$index, 'protocol' => $protocol, 'transaction_id' => 'synthetic-'.$protocol, 'last_sequence' => 1, 'meter_start_wh' => 1000, 'last_meter_wh' => 1250, 'started_at' => '2026-01-01 00:00:00', 'ended_at' => '2026-01-01 00:01:00', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('csms_inbox_events')->insert(['link_id' => $link, 'event_id' => 'synthetic-recovery-'.$protocol, 'fingerprint' => hash('sha256', $protocol), 'type' => 'ended', 'sequence' => 1, 'meter_wh' => 1250, 'occurred_at' => '2026-01-01 00:01:00', 'outcome' => 'applied', 'created_at' => now()]);
        }
    });
} elseif ($action === 'fingerprint') {
    // A consistent read includes every public table and sequence, not a table allowlist.
    $result = DB::transaction(function () {
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        DB::statement("SET LOCAL statement_timeout = '10s'");
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
        if (count($tables) < 30 || count($tables) > 1024) {
            throw new RuntimeException('Unexpected recovery schema size.');
        }
        $manifest = [];
        $rowCount = 0;
        foreach ($tables as $table) {
            $name = $table->tablename;
            $quoted = '"public"."'.str_replace('"', '""', $name).'"';
            $rows = DB::select('SELECT row_to_json(t)::text AS wire FROM '.$quoted.' AS t ORDER BY row_to_json(t)::text LIMIT 2049');
            if (count($rows) > 2048) {
                throw new RuntimeException('Recovery fixture row bound exceeded.');
            }
            $hash = hash_init('sha256');
            foreach ($rows as $row) {
                if (strlen($row->wire) > 524288) {
                    throw new RuntimeException('Recovery fixture row byte bound exceeded.');
                }
                hash_update($hash, strlen($row->wire).':'.$row->wire);
            }
            $manifest[] = [$name, count($rows), hash_final($hash)];
            $rowCount += count($rows);
        }
        $sequences = DB::select("SELECT sequencename, start_value, min_value, max_value, increment_by, cycle, cache_size, last_value FROM pg_sequences WHERE schemaname = 'public' ORDER BY sequencename");

        return ['table_count' => count($tables), 'row_count' => $rowCount, 'sequence_count' => count($sequences), 'fingerprint_sha256' => hash('sha256', json_encode([$manifest, $sequences], JSON_THROW_ON_ERROR))];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} elseif ($action === 'verify') {
    foreach (['users' => 2, 'vehicles' => 1, 'stations' => 2, 'vendor_companies' => 1, 'vendor_sites' => 2, 'vendor_chargers' => 2, 'vendor_connectors' => 2, 'charging_evses' => 2, 'charging_connector_mappings' => 2, 'reservations' => 2, 'charging_sessions' => 2, 'csms_bindings' => 2, 'charger_commands' => 2, 'csms_session_links' => 2, 'csms_inbox_events' => 2] as $table => $count) {
        if (DB::table($table)->count() !== $count) {
            throw new RuntimeException('Restored synthetic domain count differs.');
        }
    }
    foreach (\App\Models\ChargerCommand::all() as $command) {
        if (($command->payload['id_token'] ?? null) !== 'synthetic-recovery-token' || $command->status !== 'failed') {
            throw new RuntimeException('Restored command secret or terminal state differs.');
        }
    }
    $links = DB::table('csms_session_links as link')->join('charging_sessions as session', 'session.id', '=', 'link.charging_session_id')->join('reservations as booking', 'booking.id', '=', 'session.reservation_id')->join('charging_connector_mappings as mapping', 'mapping.id', '=', 'booking.connector_mapping_id')->join('charging_evses as evse', 'evse.id', '=', 'mapping.evse_id')->whereColumn('link.connector_mapping_id', 'mapping.id')->whereColumn('session.charging_evse_id', 'evse.id')->whereColumn('link.binding_id', 'evse.binding_id')->where('session.energy_wh', 250)->where('session.status', 'stopped')->pluck('link.protocol')->sort()->values()->all();
    if ($links !== ['1.6', '2.0.1'] || DB::table('finance_invoices')->exists()) {
        throw new RuntimeException('Restored source relationships or read-only financial boundary differs.');
    }
    echo "PASS: synthetic canonical topology, both recorded protocols and encrypted command payloads restored; no commands dispatched.\n";
} else {
    throw new RuntimeException('Unknown domain recovery action.');
}
