<?php

use App\Models\Station;
use App\Models\User;

// Reuse the strict local/SQLite/random-run guard. Never seed a deployed database.
ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$admin = User::create(['name' => 'Synthetic map Admin', 'email' => 'map-admin-'.$run.'@example.test', 'password' => $password]);
$admin->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
$name = 'Synthetic Admin map '.$run;
Station::whereKey($f['station_id'])->update(['name' => $name]);
echo json_encode([...$f, 'name' => $name, 'admin_email' => $admin->email, 'customer_email' => 'canonical-customer-'.$run.'@example.test', 'fixture_only' => true], JSON_THROW_ON_ERROR);
