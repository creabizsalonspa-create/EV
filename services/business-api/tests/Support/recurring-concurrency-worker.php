<?php

use App\Models\User;
use App\Services\Commercial\PaymentMandates;
use App\Services\Commercial\SubscriptionCollections;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'pgsql' || ! preg_match('/_(review|test|testing)$/', DB::connection()->getDatabaseName())) {
    exit(2);
}
[$script,$operation,$invoice,$actor,$gate,$ready,$calls,$crash] = $argv;
config(['payments.provider' => 'stripe_test', 'payments.stripe_test_secret' => 'sk_test_contractonly', 'payments.hosted_return_url' => 'https://example.test/payments', 'payments.collection_enabled' => true]);
Http::preventStrayRequests();
Http::fake(function ($r) use ($calls, $crash, $actor) {
    $path = parse_url($r->url(), PHP_URL_PATH);
    file_put_contents($calls, json_encode([$r->method(), $path])."\n", FILE_APPEND | LOCK_EX);
    $pi = ['id' => 'pi_Contract', 'livemode' => false, 'customer' => Crypt::decryptString(DB::table('payment_customer_accounts')->where('user_id', $actor)->value('provider_customer')), 'amount' => 1050, 'currency' => 'aed', 'status' => 'requires_capture', 'amount_capturable' => 1050];
    if ($path === '/v1/payment_intents') {
        $pi['metadata'] = $r['metadata'];
        file_put_contents($calls.'.provider', json_encode($pi), LOCK_EX);
        if ($crash === 'crash') {
            exit(91);
        }

        return Http::response($pi);
    }
    if ($path === '/v1/payment_intents/search') {
        return Http::response(['data' => [json_decode(file_get_contents($calls.'.provider'), true)], 'has_more' => false]);
    }
    if ($path === '/v1/payment_intents/pi_Contract/capture') {
        return Http::response(array_replace($pi, ['status' => 'succeeded', 'amount_received' => 1050]));
    }
    if ($path === '/v1/payment_intents/pi_Contract/cancel') {
        return Http::response(array_replace($pi, ['status' => 'canceled']));
    }
    throw new LogicException('Unexpected synthetic provider request.');
});
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}if (! is_file($gate)) {
    exit(2);
}
try {
    if ($operation === 'revoke') {
        app(PaymentMandates::class)->revoke(User::findOrFail((int) $actor), (int) DB::table('payment_mandates')->where('user_id', $actor)->value('id'), 1);
    } else {
        app(SubscriptionCollections::class)->collect((int) $invoice);
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(2);
}
