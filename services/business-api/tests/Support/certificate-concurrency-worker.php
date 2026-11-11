<?php

use App\Http\Controllers\ChargerAdmissionController;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\ChargerCertificates;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    exit(2);
}
$v = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
config(['csms.certificate_management_enabled' => true, 'csms.certificate_issuer_bundle' => $v['bundle'], 'csms.certificate_issuer_pins' => $v['pins']]);
$actor = User::findOrFail($v['actor']);
$company = VendorCompany::findOrFail($v['company']);
$charger = VendorCharger::findOrFail($v['charger']);
touch($argv[3]);
$deadline = microtime(true) + 15;
while (! is_file($argv[2]) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($argv[2])) {
    exit(3);
}
try {
    if ($v['operation'] === 'credential') {
        $r = Request::create('/', 'POST', ['expected_version' => $v['intent']['expected_version'], 'days' => 1]);
        $r->setUserResolver(fn () => $actor);
        app(ChargerAdmissionController::class)->credential($r, $company, $charger);
        echo '201';
    } elseif ($v['operation'] === 'cancel') {
        app(ChargerCertificates::class)->resolve($actor, $company, $charger, ['idempotency_key' => $v['intent']['idempotency_key'], 'expected_actor_id' => $actor->id, 'expected_profile_id' => $v['intent']['expected_profile_id'], 'expected_action' => 'enroll', 'cancel_unissued' => true]);
        echo '200';
    } else {
        app(ChargerCertificates::class)->apply($actor, $company, $charger, $v['operation'], $v['intent']);
        echo '200';
    }
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
} catch (Throwable $e) {
    fwrite(STDERR, $e::class);
    exit(1);
}
