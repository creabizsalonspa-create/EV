<?php

use App\Models\CostCenter;
use App\Models\Fleet;
use App\Models\User;

ob_start();
require __DIR__.'/canonical-topology.php'; // Strict local SQLite/random-run guard.
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$admin = User::create(['name' => 'Synthetic fleet Admin', 'email' => 'fleet-admin-'.$run.'@example.test', 'password' => $password]);
$admin->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
$fleet = Fleet::create(['owner_id' => $customer->id, 'name' => 'Synthetic fleet '.$run, 'country' => 'AE']);
CostCenter::create(['fleet_id' => $fleet->id, 'code' => 'OPS', 'name' => 'Synthetic operations']);
echo json_encode([...$f, 'fleet_id' => $fleet->id, 'fleet_name' => $fleet->name, 'admin_email' => $admin->email, 'customer_email' => $customer->email, 'outsider_email' => $owner->email, 'fixture_only' => true], JSON_THROW_ON_ERROR);
