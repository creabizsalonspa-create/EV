<?php

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
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
$partnerName = 'Synthetic partner map '.$run;
$provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic map provider', 'connection_key' => 'map-'.$run, 'authorized' => true]);
OcpiRecord::create([
    'ocpi_provider_id' => $provider->id, 'module' => 'locations', 'country_code' => 'NL', 'party_id' => 'MAP',
    'external_id' => $run, 'payload' => ['synthetic' => true], 'content_hash' => hash('sha256', $run),
    'normalized' => ['publish' => true, 'name' => $partnerName, 'country' => 'ARE', 'latitude' => 25, 'longitude' => 55],
    'source_updated_at' => now()->subHour(), 'received_at' => now(),
]);
echo json_encode([...$f, 'name' => $name, 'partner_name' => $partnerName, 'admin_email' => $admin->email, 'customer_email' => 'canonical-customer-'.$run.'@example.test', 'fixture_only' => true], JSON_THROW_ON_ERROR);
