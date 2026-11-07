<?php

use App\Models\User;
use App\Services\Commercial\Documents\FinancialDocuments;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('DB_CONNECTION') !== 'pgsql') {
    exit(2);
}
[$script, $actor, $source, $ready, $gate] = $argv;
$kind = $argv[5] ?? 'charging_invoice';
if (! in_array($kind, ['charging_invoice', 'vendor_settlement', 'corporate_statement'], true)) {
    exit(2);
}
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(2);
}
try {
    $doc = app(FinancialDocuments::class)->generate(User::findOrFail((int) $actor), $kind, (int) $source, 'en');
    echo json_encode(['id' => $doc->id, 'number' => $doc->number, 'pdf_sha256' => $doc->pdf_sha256], JSON_THROW_ON_ERROR);
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode()]);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e)."\n");
    exit(2);
}
