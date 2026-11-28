<?php

namespace Tests\Feature;

use App\Http\Controllers\OcpiController;
use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CdrReconciler;
use App\Services\Ocpi\CdrSessionClaims;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        // Role denial precedes private source hydration; only eligible vendor
        // identities reach the separate, existence-hiding tenant check above.
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson($url)
            ->assertForbidden()->assertJsonMissingPath('data')
            ->assertDontSee('PRIVATE-DRIVER-IDENTITY');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertOk();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_private_cdr_read_rechecks_current_actor_epoch_and_provider_tenant_before_hydration(): void
    {
        [$provider, $cdr] = $this->source();
        $owner = User::findOrFail($provider->owner_id);
        $request = Request::create('/api/ocpi/providers/'.$provider->id.'/records/cdrs', 'GET');
        // Use a deliberately retained HTTP request actor snapshot. The
        // endpoint must not rely on the old Eloquent instance to decide
        // access after another transaction changes account/provider authority.
        $request->setUserResolver(static fn () => $owner);
        $controller = app(OcpiController::class);
        $reconciler = app(CdrReconciler::class);
        $response = $controller->records($request, $provider, 'cdrs', $reconciler);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('PRIVATE-DRIVER-IDENTITY', $response->getContent());

        DB::table('users')->where('id', $owner->id)->increment('auth_epoch');
        try {
            $controller->records($request, $provider, 'cdrs', $reconciler);
            $this->fail('A previous-login actor was allowed to read private partner source.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        DB::table('users')->where('id', $owner->id)->update(['auth_epoch' => $owner->auth_epoch]);
        DB::table('ocpi_providers')->where('id', $provider->id)->update([
            'owner_id' => User::factory()->create(['role' => 'vendor'])->id,
        ]);
        try {
            $controller->records($request, $provider, 'cdrs', $reconciler);
            $this->fail('A previous owner was allowed to read the reassigned partner.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame($cdr->content_hash, $cdr->fresh()->content_hash);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
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

    public function test_reused_reconciler_rechecks_immutable_claims_after_new_source_ingress(): void
    {
        [$provider, $first, $raw] = $this->source();
        $reconciler = app(CdrReconciler::class);
        $this->assertSame('source_consistent', $reconciler->inspect($provider, $first)['status']);

        // The same long-lived service must not retain a once-clean snapshot.
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [
            array_replace($raw, ['id' => 'cdr-later-same-session']),
        ]);
        $second = OcpiRecord::where('ocpi_provider_id', $provider->id)
            ->where('module', 'cdrs')->where('external_id', 'cdr-later-same-session')->firstOrFail();
        foreach ([$first, $second] as $record) {
            $report = $reconciler->inspect($provider, $record);
            $this->assertSame('requires_review', $report['status']);
            $this->assertContains('multiple_cdrs_for_session', $report['issues']);
            $this->assertFalse($report['billing_eligible']);
            $this->assertFalse($report['local_invoice_created']);
        }
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertSame($raw, $first->fresh()->payload);
    }

    public function test_large_verified_cdr_history_is_compared_without_the_legacy_page_limit(): void
    {
        [$provider, $cdr, $raw] = $this->source();
        $sync = app(RecordSynchronizer::class);
        $reconciler = app(CdrReconciler::class);
        $sync->ingest($provider, 'cdrs', [array_replace($raw, ['id' => 'other-party', 'party_id' => 'ALT', 'session_id' => 'session-1'])]);
        $largeArchive = [];
        for ($i = 0; $i < 1001; $i++) {
            $largeArchive[] = array_replace($raw, ['id' => 'archive-'.$i, 'session_id' => 'archive-session-'.$i]);
        }
        foreach (array_chunk($largeArchive, 200) as $page) {
            $sync->ingest($provider, 'cdrs', $page);
        }
        $this->assertSame('source_consistent', $reconciler->inspect($provider, $cdr)['status']);

        $legacy = OcpiRecord::where('external_id', 'archive-0')->firstOrFail();
        DB::table('ocpi_records')->where('id', $legacy->id)->update([
            'cdr_session_claim_key' => null, 'cdr_session_claim_source_hash' => null,
        ]);
        $this->assertContains('history_exceeds_comparison_limit', $reconciler->inspect($provider, $cdr)['issues']);
        $backfill = app(CdrSessionClaims::class)->backfill($provider->id, $legacy->id - 1, 1);
        $this->assertSame(['indexed' => 1, 'unchanged' => 0, 'requires_review' => 0, 'next_after_id' => $legacy->id], $backfill);
        $this->assertSame($legacy->getRawOriginal('payload'), $legacy->fresh()->getRawOriginal('payload'));
        $this->assertSame($legacy->updated_at->toISOString(), $legacy->fresh()->updated_at->toISOString());
        $this->assertSame('source_consistent', $reconciler->inspect($provider, $cdr)['status']);

        $sync->ingest($provider, 'cdrs', [
            array_replace($raw, ['id' => 'unlinked', 'session_id' => null]),
            array_replace($raw, ['id' => 'duplicate-claim']),
        ]);
        $report = $reconciler->inspect($provider, $cdr);
        $this->assertSame('requires_review', $report['status']);
        $this->assertSame(['history_contains_unlinked_cdrs', 'multiple_cdrs_for_session'], $report['issues']);
        $this->assertFalse($report['billing_eligible']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_cdr_indexing_command_clears_unverified_index_metadata_without_repairing_source(): void
    {
        [$provider, $cdr] = $this->source();
        $ciphertext = $cdr->getRawOriginal('payload');
        $this->assertArrayNotHasKey('cdr_session_claim_key', $cdr->toArray());
        $this->assertArrayNotHasKey('cdr_session_claim_source_hash', $cdr->toArray());
        $this->artisan('ocpi:index-cdr-claims', ['provider' => $provider->id, '--limit' => 101])->assertExitCode(2);
        $this->artisan('ocpi:index-cdr-claims', ['provider' => $provider->id, '--limit' => 1])
            ->expectsOutput('{"indexed":0,"unchanged":1,"requires_review":0,"next_after_id":null}')->assertSuccessful();

        DB::table('ocpi_records')->where('id', $cdr->id)->update(['payload' => 'unreadable-test-claim']);
        $this->artisan('ocpi:index-cdr-claims', ['provider' => $provider->id])
            ->expectsOutput('{"indexed":0,"unchanged":0,"requires_review":1,"next_after_id":null}')->assertExitCode(1);
        $this->assertNull($cdr->fresh()->cdr_session_claim_key);
        $this->assertSame('unreadable-test-claim', $cdr->fresh()->getRawOriginal('payload'));
        DB::table('ocpi_records')->where('id', $cdr->id)->update(['payload' => $ciphertext]);
        $this->artisan('ocpi:index-cdr-claims', ['provider' => $provider->id])
            ->expectsOutput('{"indexed":1,"unchanged":0,"requires_review":0,"next_after_id":null}')->assertSuccessful();
    }

    public function test_reused_reconciler_cannot_trust_stale_provider_authority_or_ownership_snapshot(): void
    {
        [$provider, $cdr] = $this->source();
        $reviewer = app(CdrReconciler::class);
        $this->assertSame('source_consistent', $reviewer->inspect($provider, $cdr)['status']);

        // The worker's cached Eloquent provider is deliberately not refreshed.
        // Another request revokes its authorization in the database.
        OcpiProvider::whereKey($provider->id)->update(['authorized' => false]);
        $revoked = $reviewer->inspect($provider, $cdr);
        $this->assertSame('requires_review', $revoked['status']);
        $this->assertContains('partner_not_authorized_and_negotiated', $revoked['issues']);
        $this->assertFalse($revoked['billing_eligible']);

        // A new credential revision is also distinct from the cached actor's
        // original authorization, even after an administrator reauthorizes.
        OcpiProvider::whereKey($provider->id)->update([
            'authorized' => true,
            'authorization_revision' => $provider->authorization_revision + 1,
        ]);
        $rotated = $reviewer->inspect($provider, $cdr);
        $this->assertSame('requires_review', $rotated['status']);
        $this->assertContains('partner_not_authorized_and_negotiated', $rotated['issues']);
        $this->assertSame('source_consistent', $reviewer->inspect($provider->fresh(), $cdr)['status']);

        // Reassigning a partner to another owner must not confer a clean
        // authorization verdict on a retained previous-owner model.
        OcpiProvider::whereKey($provider->id)->update([
            'owner_id' => User::factory()->create(['role' => 'vendor'])->id,
        ]);
        $reassigned = $reviewer->inspect($provider, $cdr);
        $this->assertSame('requires_review', $reassigned['status']);
        $this->assertContains('partner_not_authorized_and_negotiated', $reassigned['issues']);
        $this->assertFalse($reassigned['local_invoice_created']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_corrupted_historical_cdr_timestamps_fail_closed_without_source_mutation(): void
    {
        [$provider, $cdr] = $this->source();
        $original = $cdr->normalized;
        $reconciler = app(CdrReconciler::class);
        $this->assertSame('source_consistent', $reconciler->inspect($provider, $cdr)['status']);

        // These values can exist in legacy/corrupt normalized evidence even
        // when new OCPI ingress rejects them. Never parse relative/local
        // dates or silently normalize impossible days during reconciliation.
        foreach ([
            'not-a-date',
            'tomorrow',
            '2026-02-30T00:00:00Z',
            '2026-11-16T10:00:00+00:00',
            123,
        ] as $untrusted) {
            $cdr->normalized = array_replace($original, ['start_date_time' => $untrusted]);
            $review = $reconciler->inspect($provider, $cdr);
            $this->assertSame('requires_review', $review['status']);
            $this->assertContains('start_date_time_mismatch', $review['issues']);
            $this->assertFalse($review['billing_eligible']);
            $this->assertFalse($review['local_invoice_created']);
        }

        $cdr->normalized = array_replace($original, ['end_date_time' => 'bad end date']);
        $this->assertContains('end_date_time_mismatch', $reconciler->inspect($provider, $cdr)['issues']);
        $this->assertSame($original, $cdr->fresh()->normalized,
            'Comparing malformed in-memory evidence must not rewrite immutable source records');
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_current_corrupt_cdr_or_session_cannot_yield_clean_reconciliation_from_stale_snapshot(): void
    {
        [$provider, $cdr] = $this->source();
        $session = OcpiRecord::where('ocpi_provider_id', $provider->id)->where('module', 'sessions')->firstOrFail();
        $reviewer = app(CdrReconciler::class);
        $this->assertSame('source_consistent', $reviewer->inspect($provider, $cdr)['status']);

        DB::table('ocpi_records')->where('id', $cdr->id)->update(['content_hash' => str_repeat('f', 64)]);
        $report = $reviewer->inspect($provider, $cdr);
        $this->assertSame('requires_review', $report['status']);
        $this->assertContains('cdr_source_integrity_requires_review', $report['issues']);
        DB::table('ocpi_records')->where('id', $cdr->id)->update(['content_hash' => $cdr->content_hash]);

        DB::table('ocpi_records')->where('id', $session->id)->update(['normalized' => json_encode(array_replace($session->normalized, ['energy_kwh' => '99']), JSON_THROW_ON_ERROR)]);
        $report = $reviewer->inspect($provider, $cdr);
        $this->assertSame('requires_review', $report['status']);
        $this->assertContains('session_source_integrity_requires_review', $report['issues']);
        DB::table('ocpi_records')->where('id', $session->id)->update(['normalized' => $session->getRawOriginal('normalized')]);

        DB::table('ocpi_records')->where('id', $cdr->id)->update(['payload' => 'synthetic-unreadable-ciphertext']);
        $report = $reviewer->inspect($provider, $cdr->fresh());
        $this->assertSame('requires_review', $report['status']);
        $this->assertContains('cdr_source_integrity_requires_review', $report['issues']);
        $this->assertNull($report['credit_review']);
        $this->assertFalse($report['billing_eligible']);
        DB::table('ocpi_records')->where('id', $cdr->id)->update(['payload' => $cdr->getRawOriginal('payload')]);
        $this->assertSame('source_consistent', $reviewer->inspect($provider, $cdr)['status']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_credit_reference_does_not_match_corrupted_original_source(): void
    {
        [$provider, $original, $raw] = $this->source();
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [array_replace($raw, ['id' => 'credit-1', 'credit' => true, 'credit_reference_id' => 'cdr-1'])]);
        $credit = OcpiRecord::where('external_id', 'credit-1')->firstOrFail();
        $reviewer = app(CdrReconciler::class);
        $this->assertSame('matched', $reviewer->inspect($provider, $credit)['credit_review']['reference_state']);
        DB::table('ocpi_records')->where('id', $original->id)->update(['payload' => 'synthetic-unreadable-ciphertext']);
        $report = $reviewer->inspect($provider, $credit);
        $this->assertSame('requires_review', $report['status']);
        $this->assertSame('invalid_target', $report['credit_review']['reference_state']);
        $this->assertFalse($report['credit_review']['financial_authority']);
        $this->assertFalse($report['credit_review']['automatic_refund']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_multiple_cdr_claims_and_credits_never_double_count_or_rewrite_source_records(): void
    {
        [$provider, $first, $raw] = $this->source();
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [array_replace($raw, ['id' => 'cdr-2', 'credit' => true, 'credit_reference_id' => 'cdr-1'])]);
        $second = OcpiRecord::where('module', 'cdrs')->where('external_id', 'cdr-2')->firstOrFail();
        $reconciler = app(CdrReconciler::class);
        $this->assertContains('multiple_cdrs_for_session', $reconciler->inspect($provider, $first)['issues']);
        $this->assertContains('credit_requires_separate_reconciliation', $reconciler->inspect($provider, $second)['issues']);
        $review = $reconciler->inspect($provider, $second)['credit_review'];
        $this->assertSame('matched', $review['reference_state']);
        $this->assertFalse($review['financial_authority']);
        $this->assertFalse($review['automatic_refund']);
        foreach (['cdr-2' => 'invalid_target', 'unreceived-cdr' => 'not_found'] as $reference => $expected) {
            app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [array_replace($raw, ['id' => 'credit-'.$reference, 'credit' => true, 'credit_reference_id' => $reference])]);
            $credit = OcpiRecord::where('external_id', 'credit-'.$reference)->firstOrFail();
            $this->assertSame($expected, app(CdrReconciler::class)->inspect($provider, $credit)['credit_review']['reference_state']);
        }
        $this->assertSame($raw, $first->fresh()->payload);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_credit_reference_requires_same_charging_source_identity_not_just_a_matching_id(): void
    {
        [$provider, $original, $raw] = $this->source();
        $mutations = [
            'currency' => ['currency' => 'USD'],
            'location' => ['cdr_location' => [
                'id' => 'different-location', 'evse_uid' => 'evse-1', 'connector_id' => '1',
            ]],
            'connector' => ['cdr_location' => [
                'id' => 'loc-1', 'evse_uid' => 'evse-1', 'connector_id' => 'different',
            ]],
            'session' => ['session_id' => 'another-session'],
            'missing-session' => ['session_id' => null],
        ];
        foreach ($mutations as $name => $change) {
            app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [
                array_replace($raw, [
                    'id' => 'credit-identity-'.$name,
                    'credit' => true,
                    'credit_reference_id' => $original->external_id,
                ], $change),
            ]);
            $credit = OcpiRecord::where('ocpi_provider_id', $provider->id)
                ->where('module', 'cdrs')->where('external_id', 'credit-identity-'.$name)->firstOrFail();
            $report = app(CdrReconciler::class)->inspect($provider, $credit);
            $this->assertSame('requires_review', $report['status']);
            $this->assertContains('credit_requires_separate_reconciliation', $report['issues']);
            $this->assertSame('identity_mismatch', $report['credit_review']['reference_state']);
            $this->assertFalse($report['credit_review']['financial_authority']);
            $this->assertFalse($report['credit_review']['automatic_refund']);
        }
        $this->assertSame($raw, $original->fresh()->payload,
            'Read-only comparison cannot alter the immutable original CDR');
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    #[TestWith([['credit' => true]])]
    #[TestWith([['credit' => false, 'credit_reference_id' => 'cdr-1']])]
    #[TestWith([['credit' => true, 'credit_reference_id' => ['cdr-1']]])]
    public function test_malformed_credit_identity_is_rejected_atomically(array $changes): void
    {
        [$provider, $original] = $this->source();
        $raw = array_replace($original->payload, ['id' => 'invalid-credit'], $changes);
        try {
            app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [$raw]);
            $this->fail('Malformed credit identity was accepted.');
        } catch (OcpiException $e) {
            $this->assertStringContainsString('Invalid cdrs record fields', $e->getMessage());
        }
        $this->assertDatabaseMissing('ocpi_records', ['external_id' => 'invalid-credit']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
    }
}
