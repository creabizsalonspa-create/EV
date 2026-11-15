<?php

namespace Tests\Feature;

use App\Http\Controllers\RoamingMeterHistoryController;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CdrReconciler;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\SessionMeterHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class OcpiSessionMeterHistoryTest extends TestCase
{
    use CreatesSandboxRoaming;
    use RefreshDatabase;

    private function active(): array
    {
        [$owner, $driver, $other, $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $raw = $this->sourceSessionFixture($provider, $order, $this->writes[0]['body']);
        app(RecordSynchronizer::class)->ingest($provider, 'sessions', [$raw]);
        $this->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk();

        return [$owner, $driver, $other, $provider, DB::table('ocpi_roaming_orders')->find($id), $raw];
    }

    public function test_ingestion_records_only_accepted_versions_and_immutable_order_scoped_history(): void
    {
        [$owner, $driver, $other, $p, $order, $raw] = $this->active();
        $service = app(RecordSynchronizer::class);
        $service->ingest($p, 'sessions', [$raw]); // duplicate does not refresh receipt or fabricate an observation.
        $stale = $raw;
        $stale['last_updated'] = now()->subMinute()->toISOString();
        $service->ingest($p, 'sessions', [$stale]);
        $this->assertDatabaseCount('ocpi_session_meter_history', 1);
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['kwh'] = '0.020';
        $service->ingest($p, 'sessions', [$raw]);
        $path = '/api/roaming/sandbox-orders/'.$order->id.'/meter-history';
        $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('observations.total', 2)->assertJsonPath('observations.data.0.reported_energy_kwh', '0.020')->assertJsonPath('observations.data.0.energy_wh', 20)->assertJsonPath('backfilled', false)->assertJsonPath('physical_energy_verified', false)->assertJsonMissingPath('observations.data.0.lineage_hash')->assertJsonMissingPath('observations.data.0.content_hash')->assertDontSee($this->writes[0]['body']['uid']);
        $this->actingAs($other)->getJson($path)->assertNotFound();
        $operator = '/api/ocpi/providers/'.$p->id.'/sandbox-orders/'.$order->id.'/meter-history';
        $this->actingAs($owner)->getJson($operator)->assertOk()->assertJsonPath('observations.total', 2);
        $this->actingAs($other)->getJson($operator)->assertNotFound();
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_changed_source_lineage_and_new_provider_owner_cannot_inherit_customer_readings(): void
    {
        [$owner, $driver, , $p, $order, $raw] = $this->active();
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['kwh'] = '88';
        $raw['cdr_token']['uid'] = 'FOREIGN-PRIVATE-TOKEN';
        app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        $path = '/api/roaming/sandbox-orders/'.$order->id.'/meter-history';
        $this->actingAs($driver)->getJson($path)->assertOk()->assertJsonPath('observations.total', 1)->assertDontSee('FOREIGN-PRIVATE-TOKEN')->assertJsonPath('observations.data.0.reported_energy_kwh', '0.01');
        $new = User::factory()->create(['role' => 'vendor']);
        $p->update(['owner_id' => $new->id]);
        $operator = '/api/ocpi/providers/'.$p->id.'/sandbox-orders/'.$order->id.'/meter-history';
        $this->actingAs($new)->getJson($operator)->assertNotFound();
        $this->actingAs($owner)->getJson($operator)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($operator)->assertOk()->assertJsonPath('observations.total', 1);
        $this->actingAs($driver)->getJson($path)->assertOk()->assertJsonPath('observations.total', 1);
    }

    public function test_regression_stays_in_reconciliation_after_meter_recovers_without_changing_invoice_totals(): void
    {
        [, , , $p, $order, $raw] = $this->active();
        foreach (['0.009', '0.030'] as $energy) {
            $this->travel(1)->seconds();
            $raw['last_updated'] = now()->toISOString();
            $raw['kwh'] = $energy;
            app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        }
        $this->postJson('/api/roaming/sandbox-orders/'.$order->id.'/reconcile')->assertOk()->assertJsonPath('reconciliation_state', 'requires_review')->assertJsonPath('can_stop', true);
        $session = OcpiRecord::findOrFail($order->source_session_record_id);
        $this->assertTrue(app(SessionMeterHistory::class)->requiresReview($session));
        $this->assertSame(1, DB::table('ocpi_session_meter_history')->where('meter_regressed', true)->count());
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['status'] = 'COMPLETED';
        $raw['end_date_time'] = now()->toISOString();
        app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        $cdr = new OcpiRecord(['ocpi_provider_id' => $p->id, 'module' => 'cdrs', 'country_code' => 'NL', 'party_id' => 'ABC', 'payload' => ['session_id' => 'S1'], 'normalized' => ['session_id' => 'S1', 'currency' => 'EUR', 'location_id' => 'L1', 'evse_uid' => 'E1', 'connector_id' => '1', 'start_date_time' => $raw['start_date_time'], 'end_date_time' => $raw['end_date_time'], 'energy_kwh' => '0.03', 'cost' => ['excl_vat' => '0.0025']]]);
        $report = app(CdrReconciler::class)->inspect($p, $cdr);
        $this->assertSame('requires_review', $report['status']);
        $this->assertContains('session_meter_history_requires_review', $report['issues']);
        $this->assertNotContains('energy_mismatch', $report['issues']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_existing_source_is_not_backfilled_but_a_new_decrease_keeps_its_known_baseline(): void
    {
        [, , , $p, $order, $raw] = $this->active();
        DB::table('ocpi_session_meter_history')->delete(); // Simulate the pre-upgrade canonical record, not fictitious observations.
        $source = OcpiRecord::findOrFail($order->source_session_record_id);
        $received = $source->received_at->toISOString();
        app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        $this->assertDatabaseCount('ocpi_session_meter_history', 0);
        $this->assertSame($received, $source->fresh()->received_at->toISOString());
        $this->getJson('/api/roaming/sandbox-orders/'.$order->id.'/meter-history')->assertOk()->assertJsonPath('observations.total', 0)->assertJsonPath('backfilled', false);
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['kwh'] = '0.005';
        app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        $this->assertDatabaseCount('ocpi_session_meter_history', 1);
        $this->assertTrue(app(SessionMeterHistory::class)->requiresReview($source->fresh()));
    }

    public function test_failed_canonical_batch_rolls_back_observations_and_pages_remain_bounded(): void
    {
        [, , , $p, $order, $raw] = $this->active();
        $copy = $raw;
        $copy['id'] = 'S2';
        $conflict = $raw;
        $conflict['kwh'] = '99';
        try {
            app(RecordSynchronizer::class)->ingest($p, 'sessions', [$copy, $conflict]);
            $this->fail('Conflicting batch must remain atomic.');
        } catch (OcpiException) {
        }
        $this->assertDatabaseCount('ocpi_session_meter_history', 1);
        $this->assertFalse(OcpiRecord::where('module', 'sessions')->where('external_id', 'S2')->exists());
        for ($i = 1; $i <= 26; $i++) {
            $this->travel(1)->seconds();
            $raw['last_updated'] = now()->toISOString();
            $raw['kwh'] = sprintf('0.%03d', 10 + $i);
            app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        }
        $path = '/api/roaming/sandbox-orders/'.$order->id.'/meter-history';
        $through = $this->getJson($path)->assertOk()->assertJsonCount(25, 'observations.data')->assertJsonPath('observations.total', 27)->assertJsonPath('observations.last_page', 2)->json('through_id');
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['kwh'] = '0.040';
        app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        $this->getJson($path.'?page=2&through='.$through)->assertOk()->assertJsonPath('observations.total', 27)->assertJsonCount(2, 'observations.data')->assertJsonPath('observations.data.1.reported_energy_kwh', '0.01');
        $this->getJson($path)->assertOk()->assertJsonPath('observations.total', 28);
        $this->getJson($path.'?page=1000001')->assertUnprocessable();
        $this->assertCount(2, $this->writes);
    }

    public function test_unsupported_precision_and_revoked_request_fail_closed_without_rounding(): void
    {
        [, $driver, , $p, $order, $raw] = $this->active();
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['kwh'] = '0.0001';
        app(RecordSynchronizer::class)->ingest($p, 'sessions', [$raw]);
        $path = '/api/roaming/sandbox-orders/'.$order->id.'/meter-history';
        $this->getJson($path)->assertOk()->assertJsonPath('observations.data.0.energy_wh', null)->assertJsonPath('observations.data.0.reported_energy_kwh', '0.0001')->assertJsonPath('observations.data.0.precision_requires_review', true);
        $request = Request::create($path);
        $request->setUserResolver(fn () => $driver);
        DB::table('users')->where('id', $driver->id)->increment('auth_epoch');
        try {
            app(RoamingMeterHistoryController::class)->customer($request, $order->id);
            $this->fail('Old login must not receive source history.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
