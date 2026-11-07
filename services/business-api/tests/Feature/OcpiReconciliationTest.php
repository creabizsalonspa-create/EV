<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CdrReconciler;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class OcpiReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function source(array $sessionOverrides = [], array $cdrOverrides = []): array
    {
        $provider = OcpiProvider::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Private source comparison', 'connection_key' => 'compare-'.Str::uuid(), 'authorized' => true, 'version' => '2.2.1']);
        $common = ['country_code' => 'AE', 'party_id' => 'TST', 'last_updated' => now()->subMinute()->toISOString(), 'start_date_time' => now()->subHours(2)->toISOString(), 'end_date_time' => now()->subHour()->toISOString(), 'currency' => 'AED'];
        $session = array_replace($common + ['id' => 'session-1', 'status' => 'COMPLETED', 'kwh' => '12.340000', 'location_id' => 'loc-1', 'evse_uid' => 'evse-1', 'connector_id' => '1', 'total_cost' => ['excl_vat' => '12.3400', 'incl_vat' => '12.96']], $sessionOverrides);
        $cdr = array_replace($common + ['id' => 'cdr-1', 'session_id' => 'session-1', 'total_energy' => '12.34', 'total_time' => '1', 'total_cost' => ['excl_vat' => '12.34', 'incl_vat' => '12.9600'], 'cdr_location' => ['id' => 'loc-1', 'evse_uid' => 'evse-1', 'connector_id' => '1'], 'cdr_token' => ['uid' => 'PRIVATE-DRIVER-IDENTITY']], $cdrOverrides);
        app(RecordSynchronizer::class)->ingest($provider, 'sessions', [$session]);
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [$cdr]);

        return [$provider, OcpiRecord::where('module', 'cdrs')->where('ocpi_provider_id', $provider->id)->firstOrFail(), $cdr];
    }

    public function test_exact_source_comparison_does_not_bill_and_api_keeps_private_evidence_hidden(): void
    {
        [$provider, $cdr] = $this->source();
        $report = app(CdrReconciler::class)->inspect($provider, $cdr);
        $this->assertSame('source_consistent', $report['status']);
        $this->assertSame([], $report['issues']);
        $this->assertFalse($report['billing_eligible']);
        $this->assertFalse($report['local_invoice_created']);
        if (! collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'api/ocpi/providers/{provider}/records/{module}')) {
            Route::middleware('web')->group(base_path('routes/ocpi.php'));
        }
        $url = '/api/ocpi/providers/'.$provider->id.'/records/cdrs';
        $this->actingAs(User::findOrFail($provider->owner_id))->getJson($url)->assertOk()->assertJsonPath('data.0.reconciliation.status', 'source_consistent')->assertDontSee('PRIVATE-DRIVER-IDENTITY')->assertJsonMissingPath('data.0.payload')->assertJsonMissingPath('data.0.content_hash');
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertOk();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_completed_session_identity_energy_and_cost_discrepancies_require_review(): void
    {
        [$provider, $cdr] = $this->source(['status' => 'ACTIVE', 'kwh' => '12.340001', 'currency' => 'USD', 'connector_id' => '2', 'total_cost' => ['excl_vat' => '12.340001']]);
        $report = app(CdrReconciler::class)->inspect($provider, $cdr);
        $this->assertSame('requires_review', $report['status']);
        foreach (['session_not_completed', 'currency_mismatch', 'connector_id_mismatch', 'energy_mismatch', 'cost_excl_vat_mismatch', 'cost_incl_vat_missing_or_unsupported'] as $issue) {
            $this->assertContains($issue, $report['issues']);
        }
        $provider->update(['authorized' => false]);
        $this->assertContains('partner_not_authorized_and_negotiated', app(CdrReconciler::class)->inspect($provider, $cdr)['issues']);
    }

    public function test_cross_provider_or_party_session_never_matches_and_missing_reference_never_uses_heuristics(): void
    {
        [$provider, $cdr] = $this->source([], ['party_id' => 'ALT']);
        $this->assertContains('session_not_received', app(CdrReconciler::class)->inspect($provider, $cdr)['issues']);
        [$other, $unlinked] = $this->source([], ['session_id' => null]);
        $this->assertContains('session_reference_missing', app(CdrReconciler::class)->inspect($other, $unlinked)['issues']);
    }

    public function test_multiple_cdr_claims_and_credits_never_double_count_or_rewrite_source_records(): void
    {
        [$provider, $first, $raw] = $this->source();
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [array_replace($raw, ['id' => 'cdr-2', 'credit' => true, 'credit_reference_id' => 'cdr-1'])]);
        $second = OcpiRecord::where('module', 'cdrs')->where('external_id', 'cdr-2')->firstOrFail();
        $reconciler = app(CdrReconciler::class);
        $this->assertContains('multiple_cdrs_for_session', $reconciler->inspect($provider, $first)['issues']);
        $this->assertContains('credit_requires_separate_reconciliation', $reconciler->inspect($provider, $second)['issues']);
        $this->assertSame($raw, $first->fresh()->payload);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
