<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless($app->environment('local', 'testing') && config('database.default') === 'sqlite' && config('payments.provider') === 'mock' && count($argv) === 3 && preg_match('/^[a-f0-9]{8}$/D', $argv[1]), 403);
$environment = $app->environment();
try {
    $app->detectEnvironment(fn () => 'testing');
    $f = (require __DIR__.'/corporate-hosted.php')();
} finally {
    $app->detectEnvironment(fn () => $environment);
}
$emails = [];
foreach (['owner', 'driver', 'admin', 'vendor'] as $role) {
    $email = 'corporate-hosted-'.$role.'-'.$argv[1].'@example.test';
    $f[$role]->forceFill(['email' => $email, 'password' => Hash::make($argv[2])])->save();
    $emails[$role] = $email;
}
echo json_encode($emails + ['fixture_only' => true, 'owner_id' => $f['owner']->id, 'statement_id' => $f['statement']->id, 'statement_number' => $f['statement']->number, 'fleet_id' => $f['fleet']->id, 'invoice_ids' => array_map(fn ($i) => $i->id, $f['invoices']), 'amount_minor' => (int) $f['statement']->total_minor, 'currency' => $f['statement']->currency, 'minor_units' => (int) $f['statement']->minor_units]);
