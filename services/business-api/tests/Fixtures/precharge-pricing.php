<?php

use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;

// Canonical guarded local fixture supplies owned identities and simulator inventory.
ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$customer = User::where('email', 'canonical-customer-'.$run.'@example.test')->firstOrFail();
$station = Station::findOrFail($f['station_id']);
TariffVersion::create(['station_id' => $station->id, 'created_by' => $station->vendor_id, 'rate_minor_per_kwh' => 251, 'tax_bps' => 500, 'commission_bps' => 1000, 'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute()]);
// Current-slot setup is synthetic. API-created future booking policy is separately
// verified by PrechargePricingTest; no physical charging or live funds are involved.
$r = Reservation::create(['station_id' => $station->id, 'user_id' => $customer->id, 'starts_at' => now()->subMinute(), 'idempotency_key' => 'price-'.$run, 'reservation_terms_snapshot' => ['price_acceptance_required' => true]]);
echo json_encode([...$f, 'reservation_id' => $r->id, 'fixture_only' => true, 'real_money' => false], JSON_THROW_ON_ERROR);
