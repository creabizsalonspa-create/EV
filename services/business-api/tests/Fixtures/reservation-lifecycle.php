<?php

use App\Models\Reservation;
use App\Models\User;
use App\Services\Charging\ReservationLifecycle;
use App\Services\Charging\ReservationPolicy;
use Carbon\CarbonImmutable;

// Reuse the local-only guarded synthetic inventory setup; no remote data touched.
ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$customer = User::where('email', 'canonical-customer-'.$run.'@example.test')->firstOrFail();
$ids = [];
$historical = CarbonImmutable::now()->subHours(2)->startOfHour();
foreach (['current' => now()->subMinutes(5)->startOfSecond(), 'unused' => $historical->subHour(), 'arrived_unused' => $historical] as $name => $start) {
    // Preserve a legacy record with no terms; the other expired booking has
    // supported terms so the client must distinguish the fee disclosures.
    $ids[$name] = Reservation::create([
        'station_id' => $f['station_id'], 'user_id' => $customer->id,
        'starts_at' => $start->format('Y-m-d H:i:s'), 'idempotency_key' => $name.'-'.$run,
        'reservation_terms_snapshot' => $name === 'arrived_unused' ? app(ReservationPolicy::class)->publicTerms() : null,
    ])->id;
}
CarbonImmutable::withTestNow($historical, function () use ($ids, $customer): void {
    app(ReservationLifecycle::class)->checkIn(Reservation::findOrFail($ids['arrived_unused']), $customer->id);
});
echo json_encode([...$f, ...$ids], JSON_THROW_ON_ERROR);
