<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(app()->environment('local', 'testing') && config('database.default') === 'sqlite' && config('payments.provider') === 'mock' && count($argv) === 3 && preg_match('/^[a-f0-9]{8}$/D', $argv[1]), 403);
$f = (require __DIR__.'/hosted.php')();
$emails = [];
foreach (['customer', 'vendor', 'admin', 'foreign'] as $role) {
    $email = 'hosted-'.$role.'-'.$argv[1].'@example.test';
    $f[$role]->forceFill(['email' => $email, 'password' => Hash::make($argv[2])])->save();
    $emails[$role] = $email;
}
echo json_encode($emails + ['fixture_only' => true, 'invoice' => $f['invoice']->id, 'subscription' => $f['subscription']->id, 'subscription_invoice' => $f['subscription_invoice']->id, 'vendor_subscription' => $f['vendor_subscription']->id, 'vendor_subscription_invoice' => $f['vendor_subscription_invoice']->id]);
