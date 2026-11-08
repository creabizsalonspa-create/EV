<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\Invoice;
use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercise the real /api/v1 auth:sanctum route, not a test-only finance mount.
 * Payment endpoints are mock-only and must never call an actual PSP.
 */
class FinanceMutationTenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrelated_identities_cannot_issue_pay_or_refund_someone_elses_invoice(): void
    {
        Http::preventStrayRequests();
        config(['payments.provider' => 'mock']);

        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $unrelatedCustomer = User::factory()->create();
        $unrelatedVendor = User::factory()->create(['role' => 'vendor']);

        $station = Station::create([
            'vendor_id' => $vendor->id,
            'name' => 'Synthetic tenant finance station',
            'country' => 'AE',
            'currency' => 'AED',
            'timezone' => 'Asia/Dubai',
            'latitude' => 25,
            'longitude' => 55,
            'source' => 'simulator',
            'connector_type' => 'CCS2',
            'power_kw' => 50,
        ]);
        $tariff = TariffVersion::create([
            'station_id' => $station->id,
            'created_by' => $vendor->id,
            'rate_minor_per_kwh' => 100,
            'tax_bps' => 0,
            'commission_bps' => 0,
            'currency' => 'AED',
            'minor_units' => 2,
            'effective_from' => now()->subMinute(),
        ]);
        $reservation = Reservation::create([
            'user_id' => $customer->id,
            'station_id' => $station->id,
            'starts_at' => now()->subMinutes(5),
            'status' => 'confirmed',
            'idempotency_key' => 'agent9-tenant-finance',
        ]);
        $session = ChargingSession::create([
            'reservation_id' => $reservation->id,
            'status' => 'stopped',
            'energy_wh' => 1000,
            'rate_minor_per_kwh' => 100,
            'currency' => 'AED',
            'total_minor' => 100,
            'stopped_at' => now(),
        ]);
        app(FinanceService::class)->snapshotSession($session, $tariff);

        $issue = '/api/v1/finance/sessions/'.$session->id.'/invoice';
        $forged = [
            'user_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'role' => 'admin',
            'idempotency_key' => 'agent9-forged-finance',
        ];

        foreach ([$unrelatedCustomer, $unrelatedVendor] as $attacker) {
            $this->actingAs($attacker)->postJson($issue, $forged)->assertNotFound();
        }
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);

        // Prove the endpoint exists and works for the actual session owner.
        $this->actingAs($customer)->postJson($issue)->assertCreated();
        $invoice = Invoice::firstOrFail();
        $path = '/api/v1/finance/invoices/'.$invoice->id;

        foreach ([$unrelatedCustomer, $unrelatedVendor] as $attacker) {
            $this->actingAs($attacker)->getJson($path)->assertNotFound();
            $this->postJson($path.'/simulate-payment', $forged + ['amount_minor' => 100])
                ->assertNotFound();
            $this->postJson($path.'/simulate-refund', $forged + [
                'amount_minor' => 100,
                'reason' => 'Unauthorized mutation',
            ])->assertNotFound();
        }

        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_journals', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
        Http::assertNothingSent();
    }
}
