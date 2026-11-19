<?php

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\Station;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (! $app->environment('local') || DB::connection()->getDriverName() !== 'sqlite' || ! in_array($mode, ['seed', 'snapshot', 'cleanup'], true) || ! preg_match('/^[a-f0-9]{8}$/D', $run)) {
    throw new RuntimeException('Owned local SQLite observation fixture required.');
}
if ($mode === 'cleanup') {
    $rows = DB::table('charger_commands')->where('idempotency_key', 'like', 'storage-audit-'.$run.'-%')->get(['id', 'station_id']);
    $ids = $rows->pluck('id');
    foreach ($rows as $row) {
        $station = Station::findOrFail($row->station_id);
        if ($station->source !== 'simulator' || $station->name !== 'Storage audit synthetic '.$run) {
            throw new RuntimeException('Owned synthetic station required for teardown.');
        }
    }
    if (SessionLink::whereIn('start_command_id', $ids)->orWhereIn('stop_command_id', $ids)->exists() || DB::table('command_source_observations')->whereIn('command_id', $ids)->exists()) {
        throw new RuntimeException('Linked command journal must never be removed by fixture teardown.');
    }
    $deleted = DB::table('charger_commands')->whereIn('id', $ids)->delete();
    echo json_encode(['fixture_only' => true, 'unlinked_synthetic_rows_removed' => $deleted], JSON_THROW_ON_ERROR);

    return;
}
if ($mode === 'snapshot') {
    $rows = DB::table('charger_commands')->where('idempotency_key', 'like', 'storage-audit-'.$run.'-%')->orderBy('id')->get();
    echo json_encode(['fixture_only' => true, 'rows' => $rows->count(), 'storage_hash' => hash('sha256', $rows->toJson()), 'finance_invoices' => DB::table('finance_invoices')->count()], JSON_THROW_ON_ERROR);

    return;
}
$password = $argv[3] ?? '';
if (strlen($password) < 24) {
    throw new RuntimeException('Private synthetic password required.');
}
$users = [];
foreach (['admin', 'customer', 'vendor'] as $role) {
    $user = User::create(['name' => 'Synthetic storage '.$role, 'email' => 'storage-'.$role.'-'.$run.'@example.test', 'password' => Hash::make($password)]);
    $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();
    $users[$role] = $user;
}
$station = Station::create(['vendor_id' => $users['vendor']->id, 'name' => 'Storage audit synthetic '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
$after = (int) DB::table('charger_commands')->max('id');
foreach (range(1, 28) as $i) {
    $command = ChargerCommand::create(['actor_id' => $users['vendor']->id, 'station_id' => $station->id, 'action' => 'start', 'idempotency_key' => 'storage-audit-'.$run.'-'.$i, 'payload_hash' => hash('sha256', $run.'-'.$i), 'payload' => ['session_id' => 42, 'id_token' => 'PRIVATE-STORAGE-FIXTURE-TOKEN']]);
    $raw = match ($i) {
        2 => json_encode(['id_token' => 'PRIVATE-LEGACY-FIXTURE-TOKEN']),
        3 => json_encode(['id_token' => ['__creabiz_command_secret' => 1, 'ciphertext' => 'PRIVATE-CORRUPT-CIPHER']]),
        4 => 'false', 5 => json_encode(['session_id' => 42]), default => null,
    };
    if ($raw !== null) {
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => $raw]);
    }
}
echo json_encode(['fixture_only' => true, 'admin_email' => $users['admin']->email, 'customer_email' => $users['customer']->email, 'after_id' => $after, 'rows_created' => 28], JSON_THROW_ON_ERROR);
