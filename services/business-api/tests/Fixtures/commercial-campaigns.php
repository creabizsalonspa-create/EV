<?php

use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\SubscriptionService;

return static function (array $entitlements = ['campaign_slots' => 2, 'campaign_units' => 5], bool $paid = true): array {
    abort_unless(app()->environment('local', 'testing'), 403);
    if (! app()->environment('testing')) {
        abort_unless(config('database.default') === 'sqlite' && config('payments.provider') === 'mock', 403);
    }
    config(['campaigns.enabled' => true, 'campaigns.delivery_secret' => str_repeat('c', 40), 'payments.provider' => 'mock']);
    $admin = User::factory()->create(['role' => 'admin']);
    $vendor = User::factory()->create(['role' => 'vendor']);
    $other = User::factory()->create(['role' => 'vendor']);
    $customer = User::factory()->create();
    $subscriptions = app(SubscriptionService::class);
    $plan = $subscriptions->publish($admin, ['code' => 'campaign-test', 'name' => 'Vendor sponsored package', 'audience' => 'vendor', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 0, 'interval' => 'month', 'entitlements' => json_encode($entitlements)]);
    $summary = $subscriptions->subscribe($vendor, $plan->id, 'campaign-enroll');
    $subscription = $summary['subscription'];
    $invoice = $summary['invoices']->first();
    if ($paid) {
        $subscriptions->mockPay($vendor, $subscription->id, $invoice->id, 'succeeded', 'campaign-pay');
    }
    $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Campaign test simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    $values = ['invoice_id' => $invoice->id, 'station_id' => $station->id, 'title' => 'Sponsored literal <img src=x>', 'title_ar' => 'إعلان تجريبي', 'description' => 'Paid package delivery only', 'description_ar' => 'حزمة تسليم إعلان', 'country' => 'AE', 'region' => null, 'placement' => 'discovery_banner', 'budget_units' => 3, 'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString(), 'idempotency_key' => 'campaign-create'];

    return compact('admin', 'vendor', 'other', 'customer', 'subscription', 'invoice', 'station', 'values');
};
