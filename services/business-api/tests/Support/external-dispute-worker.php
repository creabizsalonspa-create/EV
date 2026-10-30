<?php

use App\Services\Commercial\Disputes\ExternalDisputes;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(app()->environment('testing') && getenv('DB_CONNECTION') === 'pgsql' && preg_match('/_(review|test|testing)$/', config('database.connections.pgsql.database')), 403);
[$script, $bodyFile, $event, $go, $ready] = $argv;
$body = json_decode(file_get_contents($bodyFile), true, 16, JSON_THROW_ON_ERROR);
config(['payments.stripe_test_secret' => 'sk_test_contractonly']);
Http::preventStrayRequests();
Http::fake(['api.stripe.com/v1/charges/ch_Dispute' => Http::response(['id' => 'ch_Dispute', 'livemode' => false, 'payment_intent' => 'pi_Dispute', 'paid' => true, 'captured' => true, 'amount_captured' => 1000, 'currency' => 'aed'])]);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($go)) {
    throw new RuntimeException('Race barrier timed out');
}
app(ExternalDisputes::class)->apply($body, $event, hash('sha256', json_encode($body)), 'provider.test');
echo 'ok';
