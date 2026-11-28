<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\CorporateBilling;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorporateCycleReviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $limit = 10000): array
    {
        $this->travelTo(Carbon::parse('2026-01-15 12:00:00'));

        return (require base_path('tests/Fixtures/corporate-billing.php'))(true, $limit, 15);
    }

    private function url(array $f): string
    {
        return '/api/commercial/corporate/agreements/'.$f['a']->id.'/cycle-review';
    }

    public function test_review_uses_completed_month_and_billing_day_without_issuing_any_statement(): void
    {
        $f = $this->fixture();
        $this->travelTo(Carbon::parse('2026-02-14 23:59:59'));
        $this->actingAs($f['owner'])->getJson($this->url($f))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('period', '2026-01')
            ->assertJsonPath('billing_day', 15)
            ->assertJsonPath('state', 'before_billing_day')
            ->assertJsonPath('total_minor', 2100)
            ->assertJsonPath('claim_count', 2);
        $this->travelTo(Carbon::parse('2026-02-15 00:00:00'));
        $response = $this->getJson($this->url($f))->assertOk()
            ->assertJsonPath('state', 'ready_for_review')
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('statement_issued', false)
            ->assertJsonPath('real_payment_processed', false)
            ->assertJsonPath('remaining_credit_minor', 10000);
        $this->assertSame(array_map(fn ($c) => $c->id, $f['claims']), array_column($response->json('claims'), 'claim_id'));
        $this->assertDatabaseCount('corporate_statements', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        app(CorporateBilling::class)->issue($f['a']->id, $f['owner']->id, [
            'period' => '2026-01', 'claim_ids' => array_map(fn ($c) => $c->id, $f['claims']),
            'idempotency_key' => 'explicit-reviewed-batch',
        ]);
        $this->getJson($this->url($f))->assertOk()
            ->assertJsonPath('state', 'no_approved_claims')
            ->assertJsonPath('claim_count', 0)
            ->assertJsonPath('remaining_credit_minor', 7900);
        $this->assertDatabaseCount('corporate_statements', 1);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_cycle_review_requires_current_manager_and_preserves_source_holds(): void
    {
        $f = $this->fixture();
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        $this->actingAs($f['driver'])->getJson($this->url($f))->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($this->url($f))->assertNotFound();
        $this->actingAs($f['admin'])->getJson($this->url($f))->assertOk()->assertJsonPath('state', 'ready_for_review');
        app(FinanceService::class)->pay($f['invoices'][0], $f['driver']->id, 100, 'cycle-review-partial');
        DB::enableQueryLog();
        $this->actingAs($f['owner'])->getJson($this->url($f))->assertOk()
            ->assertJsonPath('state', 'source_hold')
            ->assertJsonPath('blocked_count', 1)
            ->assertJsonPath('claims.0.held', true)
            ->assertJsonPath('claims.1.held', false);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        DB::flushQueryLog();
        $holdQueries = array_values(array_filter($queries, fn ($query) => str_contains($query, 'finance_payments')
            || str_contains($query, 'finance_refund_requests') || str_contains($query, 'corporate_hosted_lines')));
        $this->assertCount(1, $holdQueries, 'Claim holds must be read in one statement.');
        $this->assertStringContainsString('source_held', $holdQueries[0]);
        $this->assertDatabaseCount('corporate_statements', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_reviewed_batch_requires_exact_current_owner_consent_and_replays_without_collection(): void
    {
        config(['payments.provider' => 'mock']);
        $f = $this->fixture();
        $ids = array_map(fn ($c) => $c->id, $f['claims']);
        $payload = ['period' => '2026-01', 'claim_ids' => $ids, 'total_minor' => 2100, 'consent' => true];
        $url = $this->url($f).'/issue';
        $this->travelTo(Carbon::parse('2026-02-14 23:59:59'));
        $this->actingAs($f['owner'])->postJson($url, $payload)->assertConflict();
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        $this->actingAs($f['admin'])->getJson($this->url($f))->assertOk()
            ->assertJsonPath('can_issue_reviewed_batch', false);
        $this->postJson($url, $payload)->assertForbidden();
        $this->actingAs($f['driver'])->postJson($url, $payload)->assertNotFound();
        $this->actingAs($f['owner'])->getJson($this->url($f))->assertOk()
            ->assertJsonPath('can_issue_reviewed_batch', true);
        $this->postJson($url, array_replace($payload, ['consent' => false]))->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['total_minor' => 2101]))->assertConflict();
        $this->postJson($url, array_replace($payload, ['claim_ids' => [$ids[0]], 'total_minor' => 1050]))->assertConflict();
        $this->postJson($url, array_replace($payload, ['period' => '2026-02']))->assertConflict();
        $this->assertDatabaseCount('corporate_statements', 0);
        $before = DB::table('finance_journals')->count();
        $response = $this->postJson($url, $payload)->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('statement_issued', true)
            ->assertJsonPath('real_payment_processed', false)
            ->assertJsonPath('evidence', 'TEST_REVIEWED_STATEMENT')
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('claim_ids', $ids)
            ->assertJsonPath('total_minor', 2100)
            ->assertJsonMissingPath('request_key')
            ->assertJsonMissingPath('fingerprint');
        $id = $response->json('statement_id');
        $this->getJson($this->url($f))->assertOk()->assertJsonPath('state', 'no_approved_claims');
        $this->postJson($url, array_replace($payload, ['claim_ids' => array_reverse($ids)]))
            ->assertOk()->assertJsonPath('statement_id', $id)->assertJsonPath('replayed', true);
        $this->travelTo(Carbon::parse('2026-03-01 00:00:00'));
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('statement_id', $id);
        $this->assertDatabaseCount('corporate_statements', 1);
        $this->assertDatabaseCount('corporate_statement_lines', 2);
        $this->assertDatabaseCount('corporate_receipts', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertSame($before, DB::table('finance_journals')->count());
    }

    public function test_reviewed_batch_rechecks_holds_and_is_disabled_outside_local_mock(): void
    {
        config(['payments.provider' => 'mock']);
        $f = $this->fixture();
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        $payload = ['period' => '2026-01', 'claim_ids' => array_map(fn ($c) => $c->id, $f['claims']), 'total_minor' => 2100, 'consent' => true];
        $this->actingAs($f['owner'])->getJson($this->url($f))->assertOk()
            ->assertJsonPath('can_issue_reviewed_batch', true);
        config(['payments.provider' => 'stripe_test']);
        $this->getJson($this->url($f))->assertOk()->assertJsonPath('can_issue_reviewed_batch', false);
        $this->postJson($this->url($f).'/issue', $payload)->assertStatus(503);
        config(['payments.provider' => 'mock']);
        app(FinanceService::class)->pay($f['invoices'][0], $f['driver']->id, 100, 'reviewed-batch-new-hold');
        $this->postJson($this->url($f).'/issue', $payload)->assertConflict();
        $this->assertDatabaseCount('corporate_statements', 0);
        $this->assertDatabaseCount('corporate_statement_lines', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_credit_limit_and_ownership_change_never_create_a_partial_statement(): void
    {
        $f = $this->fixture(1500);
        $this->travelTo(Carbon::parse('2026-02-15 12:00:00'));
        $this->actingAs($f['owner'])->getJson($this->url($f))->assertOk()
            ->assertJsonPath('state', 'credit_limit')
            ->assertJsonPath('total_minor', 2100)
            ->assertJsonPath('remaining_credit_minor', 1500);
        $newOwner = User::factory()->create();
        DB::table('fleets')->where('id', $f['fleet']->id)->update(['owner_id' => $newOwner->id]);
        $this->getJson($this->url($f))->assertNotFound();
        $this->actingAs($newOwner)->getJson($this->url($f))->assertOk()
            ->assertJsonPath('state', 'credit_limit');
        $this->assertDatabaseCount('corporate_statements', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }
}
