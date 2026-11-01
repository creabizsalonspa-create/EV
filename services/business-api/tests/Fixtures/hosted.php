<?php

use App\Models\ChargingSession;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\SubscriptionService;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Str;

return static function (): array {
    abort_unless(app()->environment('local', 'testing'), 403);
    $vendor = User::factory()->create(['role' => 'vendor']);
    $customer = User::factory()->create();
    $foreign = User::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Hosted payment contract fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    $tariff = TariffVersion::create(['station_id' => $station->id, 'created_by' => $admin->id, 'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
    $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => (string) Str::ulid()]);
    $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
    app(FinanceService::class)->snapshotSession($session, $tariff);
    $invoice = app(FinanceService::class)->issue($session, $customer->id);
    $plan = app(SubscriptionService::class)->publish($admin, ['code' => 'hosted-'.Str::ulid(), 'name' => 'Hosted contract subscription', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => '{}']);
    $sub = app(SubscriptionService::class)->subscribe($customer, $plan->id, 'fixture');

    $vendorPlan = app(SubscriptionService::class)->publish($admin, ['code' => 'hosted-vendor-'.Str::ulid(), 'name' => 'Hosted vendor contract subscription', 'audience' => 'vendor', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => '{}']);
    $vendorSub = app(SubscriptionService::class)->subscribe($vendor, $vendorPlan->id, 'vendor-fixture');

    return compact('vendor', 'customer', 'foreign', 'admin', 'invoice') + ['subscription' => $sub['subscription'], 'subscription_invoice' => $sub['invoices']->first(), 'vendor_subscription' => $vendorSub['subscription'], 'vendor_subscription_invoice' => $vendorSub['invoices']->first()];
};
