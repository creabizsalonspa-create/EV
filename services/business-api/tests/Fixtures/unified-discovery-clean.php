<?php

use App\Models\OcpiProvider;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 2 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Isolated local discovery fixture required.');
}
// Retain accepted test records; withdraw only this run's synthetic publication authority.
OcpiProvider::where('connection_key', 'unified-fixture-'.$argv[1])->update(['authorized' => false]);
