<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ChargerCommandTest extends TestCase
{
    use RefreshDatabase;

    private function station(User $vendor): Station
    {
        return Station::create(['vendor_id' => $vendor->id, 'name' => 'CSMS simulation', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    public function test_only_admin_can_bind_and_only_owning_vendor_can_queue_idempotent_commands(): void
    {
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($v)->postJson('/api/stations/'.$s->id.'/csms-binding', ['identifier' => 'ev-test-case', 'protocol' => '1.6'])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/stations/'.$s->id.'/csms-binding', ['identifier' => 'physical-charger', 'protocol' => '1.6'])->assertUnprocessable();
        $this->postJson('/api/stations/'.$s->id.'/csms-binding', ['identifier' => 'ev-test-case', 'protocol' => '1.6'])->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'start', 'idempotency_key' => 'start-1'])->assertNotFound();
        $this->actingAs($v)->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'start', 'idempotency_key' => 'start-1'])->assertAccepted()->assertJsonPath('status', 'pending');
        $this->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'start', 'idempotency_key' => 'start-1'])->assertOk();
        $this->postJson('/api/stations/'.$s->id.'/commands', ['action' => 'stop', 'transaction_id' => '1', 'idempotency_key' => 'start-1'])->assertConflict();
        $this->assertDatabaseCount('charger_commands', 1);
    }

    public function test_http_submission_does_not_claim_charger_acceptance_and_is_not_replayed(): void
    {
        config(['csms.simulator_enabled' => true]);
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-dispatch', 'protocol' => '2.0.1', 'created_at' => now(), 'updated_at' => now()]);
        $c = ChargerCommand::create(['actor_id' => $v->id, 'station_id' => $s->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => 'abc']);
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'test-only-token']), '*/ocpp/*' => Http::response([['success' => true, 'messageId' => 'sim-123']])]);
        $r = app(CsmsDispatcher::class)->dispatch($c->id);
        $this->assertSame('submitted', $r->status);
        $this->assertNotSame('accepted', $r->status);
        app(CsmsDispatcher::class)->dispatch($c->id);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ocpp/2.0.1/evdriver/requestStartTransaction') && $r['evseId'] === 1 && $r->hasHeader('Authorization', 'Bearer test-only-token'));
    }

    public function test_timeout_requires_reconciliation_and_never_automatic_retry(): void
    {
        config(['csms.simulator_enabled' => true]);
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-timeout', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $c = ChargerCommand::create(['actor_id' => $v->id, 'station_id' => $s->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('b', 64), 'idempotency_key' => 'timeout']);
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'test-token']), '*/ocpp/*' => Http::failedConnection()]);
        $this->assertSame('outcome_unknown', app(CsmsDispatcher::class)->dispatch($c->id)->status);
        app(CsmsDispatcher::class)->dispatch($c->id);
        Http::assertSentCount(2);
    }

    public function test_negative_dispatch_confirmation_is_rejected_without_replay(): void
    {
        config(['csms.simulator_enabled' => true]);
        $v = User::factory()->create(['role' => 'vendor']);
        $s = $this->station($v);
        DB::table('csms_bindings')->insert(['station_id' => $s->id, 'identifier' => 'ev-test-offline', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $c = ChargerCommand::create(['actor_id' => $v->id, 'station_id' => $s->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('c', 64), 'idempotency_key' => 'offline']);
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'test-token']), '*/ocpp/*' => Http::response([['success' => false]])]);
        $this->assertSame('rejected', app(CsmsDispatcher::class)->dispatch($c->id)->status);
        app(CsmsDispatcher::class)->dispatch($c->id);
        Http::assertSentCount(2);
    }

    public function test_production_guard_rejects_dev_token_dispatch(): void
    {
        $this->expectException(HttpException::class);
        config(['csms.simulator_enabled' => false]);
        app(CsmsDispatcher::class)->dispatch(1);
    }
}
