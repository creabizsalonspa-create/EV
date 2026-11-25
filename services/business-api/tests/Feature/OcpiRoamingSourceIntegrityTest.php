<?php

namespace Tests\Feature;

use App\Models\OcpiRecord;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class OcpiRoamingSourceIntegrityTest extends TestCase
{
    use CreatesSandboxRoaming, RefreshDatabase;

    #[TestWith(['command_hash'])]
    #[TestWith(['command_actor'])]
    #[TestWith(['source_hash'])]
    #[TestWith(['source_projection'])]
    #[TestWith(['source_clock'])]
    public function test_invalid_stored_evidence_cannot_release_capacity_or_adopt_final_source(string $kind): void
    {
        [, $driver, $other, $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $raw = $this->sourceSessionFixture($provider, $order, $this->writes[0]['body'], 'COMPLETED');
        app(RecordSynchronizer::class)->ingest($provider, 'sessions', [$raw]);
        $session = OcpiRecord::where('module', 'sessions')->firstOrFail();
        $command = DB::table('ocpi_roaming_commands')->where('order_id', $id)->first();
        [$table, $key, $field, $value, $original] = match ($kind) {
            'command_hash' => ['ocpi_roaming_commands', $command->id, 'intent_hash', str_repeat('a', 64), $command->intent_hash],
            'command_actor' => ['ocpi_roaming_commands', $command->id, 'actor_id', $other->id, $command->actor_id],
            'source_hash' => ['ocpi_records', $session->id, 'content_hash', str_repeat('a', 64), $session->content_hash],
            'source_projection' => ['ocpi_records', $session->id, 'normalized', json_encode(array_replace($session->normalized, ['energy_kwh' => '99']), JSON_THROW_ON_ERROR), $session->getRawOriginal('normalized')],
            'source_clock' => ['ocpi_records', $session->id, 'received_at', now()->subDay(), $session->getRawOriginal('received_at')],
        };
        DB::table($table)->where('id', $key)->update([$field => $value]);
        $url = '/api/roaming/sandbox-orders/'.$id.'/reconcile';
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'start_accepted')->assertJsonPath('reconciliation_state', 'requires_review');
        $held = DB::table('ocpi_roaming_orders')->find($id);
        $this->assertNotNull($held->active_target_key);
        $this->assertNull($held->source_session_record_id);
        DB::table($table)->where('id', $key)->update([$field => $original]);
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'source_completed')->assertJsonPath('energy_wh', 10);
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->active_target_key);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_malformed_legacy_receipt_or_attempt_clock_requires_review(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('PostgreSQL timestamp columns reject malformed legacy clock strings.');
        }
        [, $driver, , $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $raw = $this->sourceSessionFixture($provider, $order, $this->writes[0]['body'], 'COMPLETED');
        app(RecordSynchronizer::class)->ingest($provider, 'sessions', [$raw]);
        $session = OcpiRecord::where('module', 'sessions')->firstOrFail();
        $command = DB::table('ocpi_roaming_commands')->where('order_id', $id)->first();
        $url = '/api/roaming/sandbox-orders/'.$id.'/reconcile';
        foreach ([
            ['ocpi_roaming_commands', $command->id, 'attempted_at', $command->attempted_at],
            ['ocpi_records', $session->id, 'received_at', $session->getRawOriginal('received_at')],
        ] as [$table, $key, $field, $original]) {
            foreach (['not-a-clock', 'yesterday', '2026-02-30 12:00:00'] as $invalid) {
                DB::table($table)->where('id', $key)->update([$field => $invalid]);
                $this->postJson($url)->assertOk()->assertJsonPath('state', 'start_accepted')
                    ->assertJsonPath('reconciliation_state', 'requires_review');
                $held = DB::table('ocpi_roaming_orders')->find($id);
                $this->assertNotNull($held->active_target_key);
                $this->assertNull($held->source_session_record_id);
            }
            DB::table($table)->where('id', $key)->update([$field => $original]);
        }
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'source_completed');
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->active_target_key);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_backward_source_lifecycle_cannot_replace_observed_active_state(): void
    {
        [, $driver, , $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $raw = $this->sourceSessionFixture($provider, $order, $this->writes[0]['body']);
        $service = app(RecordSynchronizer::class);
        $service->ingest($provider, 'sessions', [$raw]);
        $url = '/api/roaming/sandbox-orders/'.$id.'/reconcile';
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'source_active')
            ->assertJsonPath('source_status', 'ACTIVE')->assertJsonPath('energy_wh', 10);
        $active = DB::table('ocpi_roaming_orders')->find($id);
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['status'] = 'PENDING';
        $service->ingest($provider, 'sessions', [$raw]);
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'source_active')
            ->assertJsonPath('source_status', 'ACTIVE')->assertJsonPath('reconciliation_state', 'requires_review')
            ->assertJsonPath('energy_wh', 10)->assertJsonPath('can_stop', false);
        $held = DB::table('ocpi_roaming_orders')->find($id);
        $this->assertSame($active->source_session_hash, $held->source_session_hash);
        $this->assertSame($active->active_target_key, $held->active_target_key);
        $this->travel(1)->seconds();
        $raw['last_updated'] = now()->toISOString();
        $raw['status'] = 'ACTIVE';
        $raw['kwh'] = '0.02';
        $service->ingest($provider, 'sessions', [$raw]);
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'source_active')
            ->assertJsonPath('source_status', 'ACTIVE')->assertJsonPath('energy_wh', 20)
            ->assertJsonPath('can_stop', true);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_corrupt_cdr_projection_cannot_become_consistent_financial_lineage(): void
    {
        [, $driver, , $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $session = $this->sourceSessionFixture($provider, $order, $this->writes[0]['body'], 'COMPLETED');
        app(RecordSynchronizer::class)->ingest($provider, 'sessions', [$session]);
        $cdr = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'C1', 'session_id' => 'S1',
            'last_updated' => $session['last_updated'], 'start_date_time' => $session['start_date_time'],
            'end_date_time' => $session['end_date_time'], 'currency' => 'EUR', 'total_energy' => '0.01', 'total_time' => '0.001',
            'total_cost' => ['excl_vat' => '0.0025'], 'cdr_location' => ['id' => 'L1', 'evse_uid' => 'E1', 'connector_id' => '1'],
            'cdr_token' => $session['cdr_token'], 'authorization_reference' => $order->authorization_reference];
        app(RecordSynchronizer::class)->ingest($provider, 'cdrs', [$cdr]);
        $source = OcpiRecord::where('module', 'cdrs')->firstOrFail();
        DB::table('ocpi_records')->where('id', $source->id)->update(['normalized' => json_encode(array_replace($source->normalized, ['energy_kwh' => '99']), JSON_THROW_ON_ERROR)]);
        $url = '/api/roaming/sandbox-orders/'.$id.'/reconcile';
        $this->postJson($url)->assertOk()->assertJsonPath('state', 'source_completed')->assertJsonPath('reconciliation_state', 'requires_review');
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->source_cdr_record_id);
        DB::table('ocpi_records')->where('id', $source->id)->update(['normalized' => $source->getRawOriginal('normalized')]);
        $this->postJson($url)->assertOk()->assertJsonPath('reconciliation_state', 'source_consistent');
        $this->assertEquals($source->id, DB::table('ocpi_roaming_orders')->find($id)->source_cdr_record_id);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }
}
