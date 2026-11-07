<?php

use App\Services\Commercial\SubscriptionDunning;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Support\Facades\Date;

ob_start();
require __DIR__.'/invoice-history.php';
ob_end_clean();
$service = app(SubscriptionService::class);
$plan = $service->publish($users['admin'], ['code' => 'dunning-'.$run, 'name' => 'Synthetic dunning '.$run, 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 500, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => '{}']);
Date::setTestNow(now()->subDays(8));
try {
    $summary = $service->subscribe($users['customer'], $plan->id, 'dunning-'.$run);
} finally {
    Date::setTestNow();
}
$invoice = $summary['invoices'][0];
if (! app(SubscriptionDunning::class)->invoice($invoice->id)) {
    throw new RuntimeException('Expected synthetic overdue invoice notice');
}
echo json_encode(['invoice' => $invoice->id, 'number' => $invoice->number, 'fixture_only' => true]);
