<?php

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Payouts\PayoutService;
use App\Services\Commercial\SettlementService;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return static function (string $provider = 'mock'): array {
    abort_unless(app()->environment(['local', 'testing']), 403);
    abort_unless(app()->environment('testing') || ($provider === 'mock' && DB::getDriverName() === 'sqlite'), 403, 'Local payout fixtures require SQLite and the mock provider.');
    config(['payouts.enabled' => true]);
    $vendor = User::factory()->create(['role' => 'vendor']);
    $customer = User::factory()->create();
    $maker = User::factory()->create(['role' => 'admin']);
    $checker = User::factory()->create(['role' => 'admin']);
    $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Payout test simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $maker->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
    $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => (string) Str::ulid()]);
    $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
    $finance = app(FinanceService::class);
    $finance->snapshotSession($session, $tariff);
    $invoice = $finance->issue($session, $customer->id);
    $finance->pay($invoice, $customer->id, 1050, 'payout-fixture');
    DB::table('commercial_settlement_schedules')->insert(['vendor_id' => $vendor->id, 'currency' => 'AED', 'hold_days' => 0, 'interval_days' => 30, 'minimum_minor' => 1, 'next_due_at' => now()->subMinute(), 'enabled' => true, 'configured_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
    $settlements = app(SettlementService::class);
    $settlement = $settlements->draft($vendor->id, 'AED', $vendor->id, 'payout-fixture');
    $settlements->transition($settlement->id, $maker->id, 'approve');
    $service = app(PayoutService::class);
    $account = $service->configure($vendor->id, $maker->id, ['provider' => $provider, 'currency' => 'AED', 'account_reference' => $provider === 'mock' ? 'mock_Account' : 'acct_Payout', 'bank_reference' => $provider === 'mock' ? 'mock_Bank' : 'ba_Payout', 'idempotency_key' => 'account']);
    $service->consent($account->id, $vendor->id, 'vendor-payout-v1');

    return compact('vendor', 'customer', 'maker', 'checker', 'invoice', 'settlement', 'account');
};
