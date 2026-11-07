<?php

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Synthetic immutable accounting evidence; never deletes or rewrites posted rows.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || config('payments.provider') !== 'mock' || ! in_array($argv[1] ?? '', ['create', 'refund'], true) || ! preg_match('/^[a-f0-9]{8}$/D', $argv[2] ?? '')) {
    throw new RuntimeException('Loyalty browser evidence requires local mock SQLite and an isolated synthetic identity.');
}
$action = $argv[1];
$run = $argv[2];
$finance = app(FinanceService::class);
if ($action === 'refund') {
    if (count($argv) !== 3) {
        throw new RuntimeException('Unexpected refund fixture arguments.');
    }
    $invoice = DB::table('finance_invoices as i')->join('users as u', 'u.id', '=', 'i.user_id')->where('u.email', 'loyalty-customer-'.$run.'@example.test')->select('i.*')->first();
    if (! $invoice) {
        throw new RuntimeException('Synthetic loyalty invoice is missing.');
    }
    $credit = $finance->refund(Invoice::findOrFail($invoice->id), (int) $invoice->vendor_id, (int) $invoice->total_minor, 'loyalty-refund-'.$run, 'Synthetic loyalty refund regression');
    echo json_encode(['invoice_id' => $invoice->id, 'credit_id' => $credit->id]);
    exit;
}
if (count($argv) !== 4 || strlen($argv[3]) < 20) {
    throw new RuntimeException('Synthetic credentials are required.');
}
$users = [];
foreach (['customer', 'foreign', 'vendor', 'admin'] as $role) {
    $user = User::create(['name' => 'Synthetic loyalty '.$role, 'email' => "loyalty-{$role}-{$run}@example.test", 'password' => Hash::make($argv[3])]);
    $user->role = in_array($role, ['vendor', 'admin'], true) ? $role : 'customer';
    $user->save();
    $users[$role] = $user;
}
$station = Station::create(['vendor_id' => $users['vendor']->id, 'name' => 'Synthetic loyalty '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
$tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $users['vendor']->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
$booking = Reservation::create(['station_id' => $station->id, 'user_id' => $users['customer']->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => 'loyalty-'.$run]);
$session = ChargingSession::create(['reservation_id' => $booking->id, 'status' => 'stopped', 'energy_wh' => 100000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 10000, 'stopped_at' => now()]);
$finance->snapshotSession($session, $tariff);
$invoice = $finance->issue($session, $users['customer']->id);
$finance->pay($invoice, $users['customer']->id, 10500, 'loyalty-capture-'.$run);
echo json_encode(['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'invoice_created_at' => $invoice->created_at->toIso8601String(), 'customer_id' => $users['customer']->id, 'vendor_id' => $users['vendor']->id]);
