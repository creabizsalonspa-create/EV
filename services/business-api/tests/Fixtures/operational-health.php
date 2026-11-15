<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

ob_start();
require __DIR__.'/canonical-topology.php'; // Strict local SQLite/random-run guard.
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$admin = User::create(['name' => 'Synthetic health Admin', 'email' => 'health-admin-'.$run.'@example.test', 'password' => $password]);
$admin->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
Artisan::call('platform:health-heartbeat');
echo json_encode([...$f, 'admin_email' => $admin->email, 'customer_email' => 'canonical-customer-'.$run.'@example.test', 'fixture_only' => true], JSON_THROW_ON_ERROR);
