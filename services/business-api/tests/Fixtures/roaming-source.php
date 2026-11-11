<?php

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// CLI-only, private test fixture observation; output is captured in memory and
// never published in browser logs or accepted as client charging authority.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless(PHP_SAPI === 'cli' && $app->environment('local') && config('database.default') === 'sqlite' && config('ocpi.test_commands_enabled') && in_array(count($argv), [2, 3], true) && preg_match('/^[a-f0-9]{8}$/D', $argv[1]), 403);
$p = OcpiProvider::where('connection_key', 'managed_'.$argv[1])->firstOrFail();
abort_unless(! isset($argv[2]) || preg_match('/^[1-9][0-9]{0,9}$/D', $argv[2]), 403);
$row = DB::table('ocpi_roaming_orders')->where('provider_id', $p->id)->when(isset($argv[2]), fn ($q) => $q->where('id', (int) $argv[2]))->firstOrFail();
$driver = User::whereKey($row->user_id)->where('email', 'roaming-driver-'.$argv[1].'@example.test')->firstOrFail();
$commands = DB::table('ocpi_roaming_commands')->where('order_id', $row->id)->get(['action', 'payload_ciphertext', 'attempted_at']);
$captured = [];
foreach ($commands as $command) {
    $captured[$command->action] = ['payload' => json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR), 'attempted' => $command->attempted_at !== null];
}
echo json_encode(['order_id' => $row->id, 'token' => app(SandboxRoaming::class)->token($row), 'commands' => $captured, 'charging_sessions' => DB::table('charging_sessions as s')->join('reservations as r', 'r.id', '=', 's.reservation_id')->where('r.user_id', $driver->id)->count(), 'roaming_charging_sessions' => DB::table('charging_sessions')->where('roaming_order_id', $row->id)->count(), 'financial_invoices' => DB::table('finance_invoices')->where('user_id', $driver->id)->count()], JSON_THROW_ON_ERROR);
