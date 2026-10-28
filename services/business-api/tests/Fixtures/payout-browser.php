<?php

use App\Services\Commercial\Documents\FinancialProfiles;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || config('payments.provider') !== 'mock' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Synthetic payout browser fixture requires local SQLite, mock provider and isolated identities.');
}
$f = (require __DIR__.'/vendor-payout.php')();
foreach (['customer', 'vendor', 'maker', 'checker'] as $role) {
    $f[$role]->forceFill(['name' => 'Synthetic payout '.$role, 'email' => 'payout-'.$role.'-'.$argv[1].'@example.test', 'password' => Hash::make($argv[2])])->save();
}
app(FinancialProfiles::class)->publish($f['maker'], ['vendor_id' => $f['vendor']->id, 'country' => 'AE', 'currency' => 'AED', 'minor_units' => 2, 'legal_name' => 'Synthetic issuer '.$argv[1], 'legal_name_ar' => 'جهة إصدار تجريبية', 'address' => 'Synthetic Dubai test address', 'address_ar' => 'عنوان تجريبي دبي', 'tax_registration' => null, 'effective_from' => now()->subDay()->utc()->toDateTimeString(), 'idempotency_key' => 'browser-profile-'.$argv[1]]);
echo json_encode(['vendor' => $f['vendor']->id, 'invoice' => $f['invoice']->id, 'settlement' => $f['settlement']->id, 'account' => $f['account']->id]);
