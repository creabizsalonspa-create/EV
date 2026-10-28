<?php

use App\Services\Commercial\CorporateBilling;
use App\Services\Commercial\Documents\FinancialProfiles;
use App\Services\Commercial\SubscriptionService;

// Reuse the local-only SQLite/mock guard and canonical invoice fixture.
ob_start();
require __DIR__.'/corporate-browser.php';
$f = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$a = app(CorporateBilling::class)->publish($fleet->id, $users['admin']->id, ['currency' => 'AED', 'minor_units' => 2, 'credit_limit_minor' => 100000, 'payment_terms_days' => 30, 'billing_day' => 1, 'effective_from' => now()->subDay()->toIso8601String(), 'expires_at' => now()->addYear()->toIso8601String(), 'legal_name' => 'Synthetic native company', 'terms' => 'Synthetic published terms; no real company credit or collection', 'idempotency_key' => 'native-agreement-'.$run]);
app(FinancialProfiles::class)->publish($users['admin'], ['vendor_id' => null, 'country' => 'AE', 'currency' => 'AED', 'minor_units' => 2, 'legal_name' => 'Synthetic platform issuer', 'legal_name_ar' => 'جهة إصدار تجريبية', 'address' => 'Synthetic Dubai test address', 'address_ar' => 'عنوان تجريبي دبي', 'tax_registration' => null, 'effective_from' => now()->subDay()->utc()->toDateTimeString(), 'idempotency_key' => 'native-platform-'.$run]);
$plan = app(SubscriptionService::class)->publish($users['admin'], ['code' => 'native_'.$run, 'name' => 'Synthetic native plan', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => json_encode(['priority' => true], JSON_THROW_ON_ERROR)]);
echo json_encode($f + ['agreement_id' => $a->id, 'plan_id' => $plan->id, 'period' => now()->format('Y-m')]);
