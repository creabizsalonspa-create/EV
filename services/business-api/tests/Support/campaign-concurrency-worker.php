<?php

use App\Models\User;
use App\Services\Commercial\Campaigns\CampaignService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! preg_match('/_(review|test|testing)$/', DB::connection()->getDatabaseName())) {
    exit(2);
}
config(['campaigns.enabled' => true]);
[$script, $operation, $input, $go, $ready] = $argv;
$data = json_decode(file_get_contents($input), true, 32, JSON_THROW_ON_ERROR);
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($go) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($go)) {
    exit(2);
}
try {
    $service = app(CampaignService::class);
    $result = $operation === 'create' ? $service->create(User::findOrFail($data['vendor']), $data['values']) : $service->ingest($data['values']);
    echo json_encode(['status' => $operation === 'create' ? 201 : 200, 'id' => $result->id ?? null, 'duplicate' => is_array($result) ? $result['duplicate'] : null]);
} catch (HttpException $error) {
    echo json_encode(['status' => $error->getStatusCode()]);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage());
    exit(2);
}
