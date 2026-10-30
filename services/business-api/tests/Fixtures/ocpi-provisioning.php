<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/', $argv[1])) {
    throw new RuntimeException('OCPI registration fixture requires isolated local SQLite identities.');
}
$password = Hash::make($argv[2]);
$admin = User::create(['name' => 'Synthetic registration reviewer', 'email' => 'ocpi-admin-'.$argv[1].'@example.test', 'password' => $password]);
$admin->role = 'admin';
$admin->save();
for ($i = 0; $i < 26; $i++) {
    $vendor = User::create(['name' => 'Registration '.$argv[1].' '.$i, 'email' => 'ocpi-vendor-'.$argv[1].'-'.$i.'@example.test', 'password' => $password]);
    $vendor->role = 'vendor';
    $vendor->save();
}
echo json_encode(['admin' => $admin->email, 'vendor_id' => $vendor->id, 'fixture_only' => true]);
