<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Finance\SessionQuote;
use App\Models\Finance\TariffVersion;
use App\Models\Station;
use App\Models\User;
use App\Security\AdminTotp;
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
        // Production requires both current Admin MFA and real CSRF. Exercise
        // the genuine enrollment flow before asserting the feature's own gate.
        config(['admin_security.enforce_in_local_tests' => true]);
        $this->postJson('/api/login', [
            'email' => $f['admin']->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('admin_second_factor_required', true);
        $this->getJson($f['path'])->assertForbidden()
            ->assertJsonPath('admin_second_factor_required', true);
        $secret = $this->postJson('/api/admin/second-factor/begin', [
            'password' => 'password',
        ])->assertOk()->json('secret');
        $this->postJson('/api/admin/second-factor/confirm', [
            'code' => AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30)),
        ])->assertOk()->assertJsonPath('verified', true);
        $this->getJson($f['path'])->assertOk()->assertJsonPath('pending_tariff', null);
        // Supply the matching CSRF token after enrollment regenerates session.
        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf]);
        $this->app->instance('env', 'production');
        try {
            $this->getJson($f['path'])->assertServiceUnavailable();
            $this->postJson($f['path'], $f['body'], ['X-CSRF-TOKEN' => $csrf])->assertServiceUnavailable();
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

    public function test_pending_schedule_correction_is_immutable_and_compare_and_swap_bound(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['admin']);
        $this->getJson($f['path'])->assertOk()->assertJsonPath('active_tariff.id', $f['old']->id)
            ->assertJsonPath('pending_tariff', null)->assertHeader('Cache-Control', 'no-store, private');
        $first = $this->postJson($f['path'], $f['body'])->assertCreated()->json('id');
        $this->getJson($f['path'])->assertOk()->assertJsonPath('pending_tariff.id', $first)
            ->assertJsonMissingPath('pending_tariff.publishing_key')
            ->assertJsonMissingPath('pending_tariff.publishing_fingerprint');
        $corrected = array_replace($f['body'], [
            'expected_pending_tariff_id' => $first, 'rate_minor_per_kwh' => 275,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->postJson($f['path'], array_replace($corrected, ['effective_at' => '2026-11-25T10:33:00Z']))->assertConflict();
        $this->postJson($f['path'], array_replace($corrected, ['expected_pending_tariff_id' => $f['old']->id]))->assertConflict();
        $second = $this->postJson($f['path'], $corrected)->assertCreated()->json('id');
        $this->assertNotSame($first, $second);
        $this->postJson($f['path'], $corrected)->assertCreated()->assertJsonPath('id', $second);
        $this->postJson($f['path'], array_replace($corrected, ['idempotency_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame(250, TariffVersion::findOrFail($first)->rate_minor_per_kwh);
        $this->assertSame(275, TariffVersion::findOrFail($second)->rate_minor_per_kwh);
        $this->getJson($f['path'])->assertOk()->assertJsonPath('pending_tariff.id', $second);
        $this->actingAs($f['vendor'])->getJson($f['path'])->assertForbidden();
        $this->actingAs($f['customer'])->getJson($f['path'])->assertForbidden();
        $this->travelTo(CarbonImmutable::parse('2026-11-25T10:32:00Z'));
        $this->actingAs($f['admin'])->getJson($f['path'])->assertOk()
            ->assertJsonPath('active_tariff.id', $second)->assertJsonPath('pending_tariff', null);
        $this->assertSame($second, app(FinanceService::class)->activeTariff($f['station'])->id);
        $this->assertDatabaseCount('finance_tariff_versions', 3);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.simulated_tariff_schedule_corrected')->count());
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_pending_full_rate_blocks_immediate_publication_but_not_previous_receipt_replay(): void
    {
        $f = $this->fixture();
        $path = '/api/stations/'.$f['station']->id.'/tariffs';
        $immediate = ['rate_minor_per_kwh' => 110, 'tax_bps' => 500, 'commission_bps' => 2000, 'idempotency_key' => 'immediate-before-schedule'];
        $active = $this->actingAs($f['admin'])->postJson($path, $immediate)->assertCreated()->json('id');
        $this->postJson($f['path'], array_replace($f['body'], [
            'expected_active_tariff_id' => $active, 'commission_bps' => 2000,
        ]))->assertCreated();
        $this->postJson($path, $immediate)->assertCreated()->assertJsonPath('id', $active);
        $this->postJson($path, array_replace($immediate, ['idempotency_key' => 'admin-after-schedule']))->assertConflict();
        $this->actingAs($f['vendor'])->postJson($path, array_replace($immediate, [
            'commission_bps' => 0, 'idempotency_key' => 'vendor-after-schedule',
        ]))->assertConflict();
        config(['charging_tariffs.scheduled_enabled' => false]);
        $this->actingAs($f['admin'])->postJson($path, array_replace($immediate, ['idempotency_key' => 'disabled-schedule-hold']))->assertConflict();
        $this->assertDatabaseCount('finance_tariff_versions', 3);
        $this->travelTo(CarbonImmutable::parse('2026-11-25T10:32:00Z'));
        $this->postJson($path, array_replace($immediate, ['idempotency_key' => 'after-activation']))->assertCreated();
        $this->assertDatabaseCount('finance_tariff_versions', 4);
        $this->assertDatabaseCount('finance_invoices', 0);
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
