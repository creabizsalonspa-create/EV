<?php

use App\Services\Commercial\SubscriptionCollections;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecurringContract;

ob_start();
require __DIR__.'/invoice-history.php';
ob_end_clean();
$method = RecurringContract::mandate($users['customer']);
$service = app(SubscriptionService::class);
$plan = $service->publish($users['admin'], ['code' => 'recurring-'.$run, 'name' => 'Synthetic recurring '.$run, 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 500, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => '{}']);
$s = $service->subscribe($users['customer'], $plan->id, 'recurring-'.$run);
app(SubscriptionCollections::class)->policy($users['customer'], $s['subscription']->id, true, $method, 0);
echo json_encode(['method' => $method, 'subscription' => $s['subscription']->id, 'enrollment' => DB::table('payment_enrollments')->where('user_id', $users['customer']->id)->value('id'), 'fixture_only' => true]);
