<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\SessionQuote;
use App\Models\Finance\TariffVersion;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcceptsPrechargePricing;
use Tests\TestCase;

class ScheduledTariffTest extends TestCase
{
    use AcceptsPrechargePricing;
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25T10:00:00Z'));
        config(['charging_tariffs.scheduled_enabled' => true]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $station = Station::create([
            'vendor_id' => $vendor->id, 'name' => 'Scheduled price simulator',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $old = TariffVersion::create([
            'station_id' => $station->id, 'created_by' => $admin->id,
            'rate_minor_per_kwh' => 100, 'tax_bps' => 500, 'commission_bps' => 1000,
            'currency' => 'AED', 'minor_units' => 2, 'effective_from' => now()->subMinute(),
        ]);
        $body = [
            'effective_at' => '2026-11-25T10:32:00Z', 'expected_active_tariff_id' => $old->id,
            'expected_currency' => 'AED', 'expected_minor_units' => 2,
            'rate_minor_per_kwh' => 250, 'tax_bps' => 500, 'commission_bps' => 1000,
            'idempotency_key' => (string) Str::uuid(),
        ];
        $path = '/api/stations/'.$station->id.'/tariffs/schedule';

        return compact('vendor', 'admin', 'customer', 'station', 'old', 'body', 'path');
    }

    public function test_schedule_is_explicit_admin_only_and_disabled_by_default_or_in_production(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['vendor'])->postJson($f['path'], $f['body'])->assertForbidden();
        $this->actingAs($f['customer'])->postJson($f['path'], $f['body'])->assertForbidden();
        config(['charging_tariffs.scheduled_enabled' => false]);
        $this->actingAs($f['admin'])->postJson($f['path'], $f['body'])->assertServiceUnavailable();
        config(['charging_tariffs.scheduled_enabled' => true]);
        $this->app->instance('env', 'production');
        try {
            $this->postJson($f['path'], $f['body'])->assertServiceUnavailable();
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->assertDatabaseCount('finance_tariff_versions', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_schedule_requires_current_typed_pricing_context_and_bounded_absolute_utc_time(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin']);
        foreach (['now', '2026-02-30T10:32:00Z', '2026-11-25T10:00:59Z',
            '2026-12-26T10:00:00Z', '2026-11-25T10:32:00+00:00'] as $at) {
            $this->postJson($f['path'], array_replace($f['body'], ['effective_at' => $at]))->assertUnprocessable();
        }
        foreach (['expected_active_tariff_id' => null, 'expected_currency' => 'USD',
            'expected_minor_units' => 3] as $field => $value) {
            $this->postJson($f['path'], array_replace($f['body'], [$field => $value]))->assertConflict();
        }
        $id = $this->postJson($f['path'], $f['body'])->assertCreated()
            ->assertJsonMissingPath('publishing_key')->assertJsonMissingPath('publishing_fingerprint')->json('id');
        $this->postJson($f['path'], $f['body'])->assertCreated()->assertJsonPath('id', $id);
        $this->postJson($f['path'], array_replace($f['body'], ['rate_minor_per_kwh' => 251]))->assertConflict();
        $this->postJson($f['path'], array_replace($f['body'], ['idempotency_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame($f['old']->id, app(FinanceService::class)->activeTariff($f['station'])->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-25T10:31:59Z'));
        $this->assertSame($f['old']->id, app(FinanceService::class)->activeTariff($f['station'])->id);
        $this->travel(1)->seconds();
        $this->assertSame($id, app(FinanceService::class)->activeTariff($f['station'])->id);
        // A lost-response retry after activation returns the immutable receipt.
        $this->postJson($f['path'], $f['body'])->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('finance_tariff_versions', 2);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.simulated_tariff_scheduled')->count());
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_activation_requires_new_customer_consent_and_preserves_the_session_snapshot(): void
    {
        $f = $this->fixture();
        $newId = $this->actingAs($f['admin'])->postJson($f['path'], $f['body'])->assertCreated()->json('id');
        $reservation = $this->actingAs($f['customer'])->postJson('/api/reservations', [
            'station_id' => $f['station']->id, 'starts_at' => '2026-11-25T10:30:00Z',
            'idempotency_key' => 'scheduled-tariff-booking',
        ])->assertCreated()->json('id');
        $this->travel(30)->minutes();
        $oldConsent = $this->acceptPrechargePrice($reservation);
        $this->travel(2)->minutes();
        $this->postJson('/api/reservations/'.$reservation.'/simulate-start')->assertConflict();
        $this->assertDatabaseCount('charging_sessions', 0);
        $freshConsent = $this->acceptPrechargePrice($reservation);
        $this->assertNotSame($oldConsent, $freshConsent);
        $sessionId = $this->postJson('/api/reservations/'.$reservation.'/simulate-start')->assertCreated()->json('id');
        $quote = SessionQuote::firstOrFail();
        $this->assertSame($newId, $quote->tariff_version_id);
        $this->assertSame(250, $quote->rate_minor_per_kwh);

        // An immediate subsequent administrator rate change cannot reprice
        // an already created session or its eventual invoice.
        $this->actingAs($f['admin'])->postJson('/api/stations/'.$f['station']->id.'/tariffs', [
            'rate_minor_per_kwh' => 500, 'tax_bps' => 500, 'commission_bps' => 1000,
        ])->assertCreated();
        $this->actingAs($f['vendor'])->putJson('/api/simulations/'.$sessionId.'/meter', ['energy_wh' => 1000])->assertOk();
        $this->actingAs($f['customer'])->postJson('/api/simulations/'.$sessionId.'/stop')->assertOk();
        $invoice = app(FinanceService::class)->issue(ChargingSession::findOrFail($sessionId), $f['customer']->id);
        $this->assertSame(250, $invoice->subtotal_minor);
        $this->assertSame(13, $invoice->tax_minor);
        $this->assertSame(263, $invoice->total_minor);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
