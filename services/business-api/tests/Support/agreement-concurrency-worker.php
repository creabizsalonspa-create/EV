<?php

use App\Http\Controllers\VendorAgreementController;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    exit(2);
}
[$script, $input, $go, $ready] = $argv;
$data = json_decode(file_get_contents($input), true, 32, JSON_THROW_ON_ERROR);
$actor = User::findOrFail($data['actor']);
$company = VendorCompany::findOrFail($data['company']);
$request = Request::create('/agreement-test', 'POST', $data['values']);
$request->setUserResolver(fn () => $actor);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($go)) {
    exit(2);
}
try {
    $response = app(VendorAgreementController::class)->publish($request, $company);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getData(true)]);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(2);
}
