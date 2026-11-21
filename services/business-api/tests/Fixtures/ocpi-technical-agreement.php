<?php

use App\Models\OcpiProvider;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $action, $run] = $argv;
$database = config('database.connections.sqlite.database');
if (! $app->environment('local') || config('database.default') !== 'sqlite' || ! preg_match('/^[a-f0-9]{8}$/D', $run)
    || ! is_string($database) || ! str_starts_with($database, storage_path('framework/testing/ocpi-agreement-'.$run.'/'))
    || basename($database) !== 'db.sqlite' || is_link($database)) {
    throw new RuntimeException('OCPI technical agreement QA requires its own generated local SQLite database.');
}
if ($action === 'seed') {
    $password = Hash::make($argv[3]);
    foreach (['admin', 'vendor', 'customer'] as $role) {
        $u = User::create(['name' => 'Synthetic agreement '.$role, 'email' => 'agreement-'.$role.'-'.$run.'@example.test', 'password' => $password]);
        $u->forceFill(['role' => $role, 'email_verified_at' => now()])->save();
        $users[$role] = $u;
    }
    $p = OcpiProvider::create(['owner_id' => $users['vendor']->id, 'name' => 'Synthetic technical peer '.$run, 'connection_key' => 'agreement_'.$run, 'authorized' => false]);
    echo json_encode(['provider_id' => $p->id, 'admin' => $users['admin']->email, 'vendor' => $users['vendor']->email, 'customer' => $users['customer']->email]);
} elseif ($action === 'snapshot') {
    $rows = DB::table('ocpi_technical_agreements')->get();
    $encrypted = $rows->every(fn ($r) => ! str_contains($r->payload_ciphertext, 'Synthetic') && strlen($r->payload_ciphertext) > 100);
    $p = OcpiProvider::where('connection_key', 'agreement_'.$run)->firstOrFail();
    echo json_encode(['versions' => $rows->count(), 'decisions' => DB::table('ocpi_technical_agreement_events')->count(),
        'encrypted_policy' => $encrypted, 'provider_authorized' => $p->authorized, 'negotiated' => $p->negotiated_at !== null,
        'credential_operations' => DB::table('ocpi_credential_operations')->count(), 'source_commands' => DB::table('charger_commands')->count(),
        'invoices' => DB::table('finance_invoices')->count(), 'payments' => DB::table('finance_payments')->count(), 'refunds' => DB::table('finance_refunds')->count()]);
} else {
    throw new RuntimeException('Unknown fixture action.');
}
