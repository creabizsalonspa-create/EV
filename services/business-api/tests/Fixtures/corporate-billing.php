<?php

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Fleet;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\CorporateBilling;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return static function (bool $approveClaims = true): array {
    abort_unless(app()->environment('testing'), 403);
    $owner = User::factory()->create();
    $driver = User::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $vendor = User::factory()->create(['role' => 'vendor']);
    $fleet = Fleet::create(['owner_id' => $owner->id, 'name' => 'Corporate concurrency fixture', 'country' => 'AE']);
    DB::table('fleet_members')->insert(['fleet_id' => $fleet->id, 'user_id' => $driver->id, 'status' => 'active', 'role' => 'driver', 'created_at' => now(), 'updated_at' => now()]);
    $center = DB::table('cost_centers')->insertGetId(['fleet_id' => $fleet->id, 'code' => 'TEST', 'name' => 'Concurrency test', 'created_at' => now(), 'updated_at' => now()]);
    $service = app(CorporateBilling::class);
    $a = $service->publish($fleet->id, $admin->id, ['currency' => 'AED', 'minor_units' => 2, 'credit_limit_minor' => 1500, 'payment_terms_days' => 30, 'billing_day' => 1, 'effective_from' => now()->subDay(), 'expires_at' => now()->addYear(), 'legal_name' => 'Fixture test company', 'terms' => 'Synthetic test contract', 'idempotency_key' => 'agreement']);
    $service->accept($a->id, $owner->id);
    $invoices = [];
    $claims = [];
    for ($i = 0; $i < 2; $i++) {
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Corporate concurrency simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $admin->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
        $b = Reservation::create(['user_id' => $driver->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => (string) Str::uuid()]);
        $s = ChargingSession::create(['reservation_id' => $b->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
        app(FinanceService::class)->snapshotSession($s, $tariff);
        $invoice = app(FinanceService::class)->issue($s, $driver->id);
        $invoices[] = $invoice;
        $claim = $service->claim($a->id, $driver->id, ['invoice_id' => $invoice->id, 'cost_center_id' => $center, 'idempotency_key' => 'claim-'.$i]);
        $claims[] = $claim;
        if ($approveClaims) {
            $service->decide($claim->id, $owner->id, 'approved', 'Authorized synthetic test');
        }
    }

    return compact('owner', 'driver', 'admin', 'vendor', 'fleet', 'center', 'a', 'invoices', 'claims');
};
