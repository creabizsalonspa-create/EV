<?php

use App\Mail\GenericInboxNotice;
use App\Services\Notifications\EmailNotices;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_review')) {
    exit(2);
}
[$script, $operation, $id, $gate, $ready] = $argv;
if (! in_array($operation, ['prepare', 'deliver'], true) || dirname($gate) !== dirname($ready) || ! str_starts_with(realpath(dirname($gate)), sys_get_temp_dir().'/notification-email-race-')) {
    exit(2);
}
config(['notification_email.enabled' => true, 'app.url' => 'https://platform.example', 'mail.default' => 'array', 'mail.from.address' => 'notices@example.test']);
Mail::fake();
touch($ready);
$deadline = microtime(true) + 15;
while (! is_file($gate) && microtime(true) < $deadline) {
    usleep(5000);
}
if (! is_file($gate)) {
    exit(3);
}
$service = app(EmailNotices::class);
$result = $operation === 'prepare' ? $service->prepare() : $service->deliver($id);
echo json_encode(['result' => $result, 'sends' => Mail::sent(GenericInboxNotice::class)->count()], JSON_THROW_ON_ERROR);
