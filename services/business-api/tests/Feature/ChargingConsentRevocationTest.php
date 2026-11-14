<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChargingConsentRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function queued(string $protocol = '1.6', int $epoch = 0): array
    {
        config(['csms.simulator_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.remote_authorization_enabled' => false]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['auth_epoch' => $epoch]);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Consent safety fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-consent', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'consent-fixture']);
        $this->actingAs($customer)->withSession(['account_auth_epoch' => $epoch])->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();
        Http::fake(['*/dev/token' => Http::response(['accessToken' => 'synthetic-only-token']), '*/ocpp/*' => Http::response([['success' => true, 'messageId' => 'synthetic-consent-message']])]);

        return [$customer, $vendor, ChargerCommand::firstOrFail(), $reservation];
    }

    public function test_queued_start_cannot_outlive_global_credential_revocation_and_remains_safely_cancellable(): void
    {
        [$customer, , $command, $reservation] = $this->queued();
        $this->assertSame(0, $command->consent_auth_epoch);
        DB::table('users')->where('id', $customer->id)->update(['auth_epoch' => 1]);
        $result = app(CsmsDispatcher::class)->dispatch($command->id);
        $this->assertSame('pending', $result->status);
        $this->assertSame('pre_dispatch_actor_revoked', $result->failure_code);
        Http::assertNothingSent();
        $this->assertSame('pending_start', ChargingSession::firstOrFail()->status);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertArrayNotHasKey('consent_auth_epoch', $result->toArray());
        $this->actingAs($customer->fresh())->withSession(['account_auth_epoch' => 1])->getJson('/api/simulations')->assertOk()->assertJsonPath('data.0.start_authorization_revoked', true)->assertJsonPath('data.0.can_cancel_before_dispatch', true);
        $this->deleteJson('/api/reservations/'.$reservation->id)->assertOk();
        $this->assertSame('cancelled', $command->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_suspended_actor_and_legacy_unstamped_start_fail_closed(): void
    {
        [$customer, , $command] = $this->queued('2.0.1');
        DB::table('users')->where('id', $customer->id)->update(['account_status' => 'suspended']);
        $this->assertSame('pre_dispatch_actor_revoked', app(CsmsDispatcher::class)->dispatch($command->id)->failure_code);
        DB::table('users')->where('id', $customer->id)->update(['account_status' => 'active']);
        $command->update(['consent_auth_epoch' => null]);
        $this->assertSame('pre_dispatch_actor_revoked', app(CsmsDispatcher::class)->dispatch($command->id)->failure_code);
        Http::assertNothingSent();
    }

    public function test_current_nonzero_epoch_can_dispatch_once_and_vendor_suspension_blocks_new_work(): void
    {
        [$customer, $vendor, $command] = $this->queued('2.0.1', 4);
        $this->assertSame(4, $command->consent_auth_epoch);
        DB::table('users')->where('id', $vendor->id)->update(['account_status' => 'suspended']);
        $this->assertSame('pre_dispatch_vendor_unavailable', app(CsmsDispatcher::class)->dispatch($command->id)->failure_code);
        Http::assertNothingSent();
        DB::table('users')->where('id', $vendor->id)->update(['account_status' => 'active']);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($command->id)->status);
        DB::table('users')->where('id', $customer->id)->update(['auth_epoch' => 5]);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($command->id)->status);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_already_authorized_queued_stop_is_not_blocked_or_replayed_by_later_logout(): void
    {
        [$customer, , $start] = $this->queued();
        $session = ChargingSession::firstOrFail();
        $session->update(['status' => 'charging']);
        DB::table('csms_session_links')->where('charging_session_id', $session->id)->update(['started_at' => now(), 'transaction_id' => '123', 'last_sequence' => 0]);
        $this->postJson('/api/charging-sessions/'.$session->id.'/csms-stop')->assertAccepted();
        $stop = ChargerCommand::where('action', 'stop')->firstOrFail();
        DB::table('users')->where('id', $customer->id)->update(['auth_epoch' => 1]);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($stop->id)->status);
        $this->assertSame('submitted', app(CsmsDispatcher::class)->dispatch($stop->id)->status);
        Http::assertSentCount(2);
        $this->assertSame('stopping', $session->fresh()->status);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_revocation_does_not_describe_an_unknown_dispatch_as_an_unsent_start(): void
    {
        [$customer, , $command] = $this->queued();
        $command->update(['status' => 'outcome_unknown']);
        DB::table('users')->where('id', $customer->id)->update(['auth_epoch' => 1]);
        $this->actingAs($customer->fresh())->withSession(['account_auth_epoch' => 1])->getJson('/api/simulations')->assertOk()
            ->assertJsonPath('data.0.start_authorization_revoked', false)
            ->assertJsonPath('data.0.can_cancel_before_dispatch', false)
            ->assertJsonPath('data.0.start_command_status', 'outcome_unknown');
        $this->assertSame('outcome_unknown', app(CsmsDispatcher::class)->dispatch($command->id)->status);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
