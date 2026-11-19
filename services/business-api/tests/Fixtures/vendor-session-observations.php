<?php

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
    echo json_encode(['fixture_only' => true, 'rows' => $rows->count(), 'session_hash' => hash('sha256', $rows->toJson()), 'commands' => DB::table('charger_commands')->count(), 'invoices' => DB::table('finance_invoices')->count()], JSON_THROW_ON_ERROR);

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
    ChargingSession::create(['reservation_id' => $booking->id, 'status' => 'stopped', 'energy_wh' => 2500, 'rate_minor_per_kwh' => 999, 'currency' => 'AED', 'total_minor' => 777, 'stopped_at' => now()]);
}
echo json_encode(['fixture_only' => true, 'owner_email' => $owner->email, 'staff_email' => $staff->email, 'company_id' => $company->id, 'membership_id' => $membership->id, 'station_name' => $station->name], JSON_THROW_ON_ERROR);
