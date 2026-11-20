<?php

use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (! $app->environment('local') || DB::connection()->getDriverName() !== 'sqlite' || ! in_array($mode, ['seed', 'snapshot', 'revoke'], true) || ! preg_match('/^[a-f0-9]{8}$/D', $run)) {
    throw new RuntimeException('Owned loopback local SQLite fixture required.');
}
$email = 'staff-observation-'.$run.'@example.test';
if ($mode !== 'seed') {
    $staff = User::where('email', $email)->firstOrFail();
    $member = VendorCompanyStaff::where('user_id', $staff->id)->firstOrFail();
    if ($mode === 'revoke') {
        $member->update(['status' => 'revoked']);
    }
    $rows = DB::table('charging_sessions')->join('reservations', 'reservations.id', '=', 'charging_sessions.reservation_id')->where('reservations.idempotency_key', 'like', 'staff-observation-'.$run.'-%')->orderBy('charging_sessions.id')->get(['charging_sessions.*']);
    $history = DB::table('csms_inbox_events as event')->join('csms_session_links as link', 'link.id', '=', 'event.link_id')->whereIn('link.charging_session_id', $rows->pluck('id'))->orderBy('event.id')->get(['event.*']);
    $finance = hash_init('sha256');
    foreach (['finance_invoices', 'finance_journals', 'finance_entries', 'finance_payments', 'finance_refunds'] as $table) {
        hash_update($finance, $table."\0".DB::table($table)->orderBy('id')->get()->toJson());
    }
    $commands = DB::table('charger_commands')->where('idempotency_key', 'staff-meter-'.$run)->orderBy('id')->get()->toJson();
    echo json_encode(['canonical_finance_hash' => hash_final($finance), 'owned_command_hash' => hash('sha256', $commands), 'meter_history_hash' => hash('sha256', $history->toJson()), 'fixture_only' => true, 'rows' => $rows->count(), 'session_hash' => hash('sha256', $rows->toJson()), 'commands' => DB::table('charger_commands')->count(), 'invoices' => DB::table('finance_invoices')->count()], JSON_THROW_ON_ERROR);

    return;
}
$password = $argv[3] ?? '';
if (strlen($password) < 24) {
    throw new RuntimeException('Private synthetic password required.');
}
$owner = User::create(['name' => 'Synthetic observation owner', 'email' => 'owner-observation-'.$run.'@example.test', 'password' => Hash::make($password)]);
$owner->forceFill(['role' => 'vendor', 'email_verified_at' => now()])->save();
$staff = User::create(['name' => 'Synthetic observation staff', 'email' => $email, 'password' => Hash::make($password)]);
$staff->forceFill(['email_verified_at' => now()])->save();
$driver = User::create(['name' => 'PRIVATE-DRIVER', 'email' => 'private-driver-'.$run.'@example.test', 'password' => Hash::make($password)]);
$company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic observations '.$run, 'country' => 'AE', 'trade_license_number' => 'PRIVATE-LICENSE', 'license_expires_on' => now()->addYear(), 'status' => 'approved']);
$membership = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'permissions' => ['inventory.read'], 'status' => 'active', 'accepted_at' => now()]);
$station = Station::create(['vendor_id' => $owner->id, 'name' => 'Observed synthetic station '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Synthetic observation site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
foreach (range(1, 27) as $i) {
    $booking = Reservation::create(['user_id' => $driver->id, 'station_id' => $station->id, 'starts_at' => now()->subHours($i), 'idempotency_key' => 'staff-observation-'.$run.'-'.$i]);
    $latest = ChargingSession::create(['reservation_id' => $booking->id, 'status' => 'stopped', 'energy_wh' => 2500, 'rate_minor_per_kwh' => 999, 'currency' => 'AED', 'total_minor' => 777, 'stopped_at' => now()]);
}
$meterSession = null;
if (($argv[4] ?? '') === 'meters') {
    $at = now()->subMinute();
    $identifier = 'synthetic-staff-meter-'.$run;
    $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => $identifier, 'protocol' => '2.0.1', 'created_at' => $at, 'updated_at' => $at]);
    $command = ChargerCommand::create(['actor_id' => $owner->id, 'station_id' => $station->id, 'action' => 'start', 'status' => 'source_observed', 'idempotency_key' => 'staff-meter-'.$run, 'payload_hash' => hash('sha256', $run), 'payload' => ['session_id' => $latest->id]]);
    $link = DB::table('csms_session_links')->insertGetId(['charging_session_id' => $latest->id, 'binding_id' => $binding, 'start_command_id' => $command->id, 'identifier' => $identifier, 'protocol' => '2.0.1', 'transaction_id' => 'synthetic-'.$run, 'meter_start_wh' => 1000000, 'last_meter_wh' => 1002500, 'last_sequence' => 26, 'started_at' => $at, 'ended_at' => $at->copy()->addSeconds(26), 'last_event_at' => $at->copy()->addSeconds(26), 'created_at' => $at, 'updated_at' => $at]);
    foreach (range(0, 26) as $i) {
        DB::table('csms_inbox_events')->insert(['link_id' => $link, 'event_id' => 'PRIVATE-staff-meter-'.$run.'-'.$i, 'fingerprint' => hash('sha256', $run.'-'.$i), 'sequence' => $i, 'type' => $i === 0 ? 'started' : ($i === 26 ? 'ended' : 'meter'), 'meter_wh' => 1000000 + min($i, 25) * 100, 'occurred_at' => $at->copy()->addSeconds($i), 'outcome' => 'applied', 'created_at' => $at->copy()->addSeconds($i + 1)]);
    }
    $meterSession = $latest->id;
}
echo json_encode(['meter_session_id' => $meterSession, 'fixture_only' => true, 'owner_email' => $owner->email, 'staff_email' => $staff->email, 'company_id' => $company->id, 'membership_id' => $membership->id, 'station_name' => $station->name], JSON_THROW_ON_ERROR);
