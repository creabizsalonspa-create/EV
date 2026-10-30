<?php

use App\Models\Reservation;
use App\Models\User;

// Reuse the local-only guarded synthetic inventory setup; no remote data touched.
ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$customer = User::where('email', 'canonical-customer-'.$run.'@example.test')->firstOrFail();
$ids = [];
foreach (['current' => now()->subMinutes(5)->startOfSecond(), 'unused' => now()->subHours(2)->startOfHour()] as $name => $start) {
    $ids[$name] = Reservation::create(['station_id' => $f['station_id'], 'user_id' => $customer->id, 'starts_at' => $start->format('Y-m-d H:i:s'), 'idempotency_key' => $name.'-'.$run])->id;
}
echo json_encode([...$f, ...$ids], JSON_THROW_ON_ERROR);
