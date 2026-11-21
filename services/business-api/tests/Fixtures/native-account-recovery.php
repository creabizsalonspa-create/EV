<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$run = $argv[1] ?? '';
$password = $argv[2] ?? '';
$role = $argv[3] ?? 'customer';
if (! $app->environment('local') || DB::connection()->getDriverName() !== 'sqlite' || ! preg_match('/^[a-f0-9]{8}$/D', $run) || strlen($password) < 24 || ! in_array($role, ['customer', 'vendor'], true)) {
    throw new RuntimeException('Owned local SQLite synthetic fixture required.');
}
$user = User::create(['name' => 'Synthetic native recovery', 'email' => 'native-recovery-'.$run.'@example.test', 'password' => Hash::make($password), 'role' => $role]);
$user->forceFill(['role' => $role])->save();
echo json_encode(['fixture_only' => true, 'role' => $role, 'email' => $user->email, 'token' => Password::createToken($user)], JSON_THROW_ON_ERROR);
