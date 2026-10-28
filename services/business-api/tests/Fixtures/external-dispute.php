<?php

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Support\Facades\DB;

return static function (): array {
    abort_unless(app()->environment('testing'), 403);
    $user = User::factory()->create();
    $vendor = User::factory()->create(['role' => 'vendor']);
    $admin = User::factory()->create(['role' => 'admin']);
    $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Dispute test simulator', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    $reservation = Reservation::create(['user_id' => $user->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'dispute-fixture']);
    $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 10000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 1000, 'stopped_at' => now()]);
    $finance = app(FinanceService::class);
    $invoice = $finance->issue($session, $user->id);
    $finance->pay($invoice, $user->id, 1000, 'dispute-fixture');
    DB::table('gateway_checkouts')->insert(['invoice_id' => $invoice->id, 'user_id' => $user->id, 'provider' => 'stripe_test', 'provider_reference' => 'pi_Dispute', 'request_key' => hash('sha256', 'fixture'), 'fingerprint' => hash('sha256', 'fixture'), 'currency' => 'AED', 'amount_minor' => 1000, 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);
    $body = ['id' => 'dp_Dispute', 'livemode' => false, 'charge' => 'ch_Dispute', 'payment_intent' => 'pi_Dispute', 'amount' => 700, 'currency' => 'aed', 'status' => 'needs_response', 'reason' => 'fraudulent', 'evidence_details' => ['due_by' => now()->addDays(3)->timestamp], 'evidence' => []];

    return compact('user', 'vendor', 'admin', 'invoice', 'body');
};
