<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || count($argv) !== 3 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[1])) {
    throw new RuntimeException('Requires isolated local SQLite and synthetic identity.');
}
foreach (['author', 'reviewer', 'customer'] as $role) {
    $user = User::create(['name' => 'Synthetic retail '.$role, 'email' => 'retail-'.$role.'-'.$argv[1].'@example.test', 'password' => Hash::make($argv[2])]);
    $user->role = $role === 'customer' ? 'customer' : 'admin';
    $user->save();
}
echo json_encode(['fixture_only' => true], JSON_THROW_ON_ERROR);
