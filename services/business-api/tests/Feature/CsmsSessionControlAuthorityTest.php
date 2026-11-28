<?php

namespace Tests\Feature;

use App\Http\Controllers\CsmsSessionController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SessionBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CsmsSessionControlAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->startOfSecond());
        config(['csms.session_bridge_enabled' => true]);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create([
            'vendor_id' => $vendor->id, 'name' => 'Current stop authority',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        DB::table('csms_bindings')->insert([
            'station_id' => $station->id, 'identifier' => 'ev-test-live-stop-auth',
            'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reservation = Reservation::create([
            'user_id' => $customer->id, 'station_id' => $station->id,
            'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'stop-authority',
        ]);
        $this->actingAs($customer)->postJson('/api/reservations/'.$reservation->id.'/csms-start')->assertAccepted();
        $session = ChargingSession::firstOrFail();

        return compact('customer', 'vendor', 'station', 'session');
    }

    private function active(ChargingSession $session): void
    {
        $session->update(['status' => 'charging']);
        DB::table('csms_session_links')->where('charging_session_id', $session->id)->update([
            'started_at' => now(), 'transaction_id' => '123', 'last_sequence' => 0,
        ]);
    }

    public function test_captured_reservation_owner_cannot_replay_a_session_after_ownership_changes(): void
    {
        $f = $this->fixture();
        $reservation = Reservation::findOrFail($f['session']->reservation_id);
        $replacement = User::factory()->create(['role' => 'customer']);
        DB::table('reservations')->where('id', $reservation->id)->update(['user_id' => $replacement->id]);
        try {
            app(SessionBridge::class)->start($reservation, $f['customer']->id, consentingActor: $f['customer']);
            $this->fail('A stale reservation owner accessed the private charging session.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('csms_session_links', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_existing_start_replay_requires_original_source_identity(): void
    {
        $f = $this->fixture();
        $reservation = Reservation::findOrFail($f['session']->reservation_id);
        DB::table('csms_bindings')->where('station_id', $f['station']->id)
            ->update(['identifier' => 'ev-test-rebound-session']);
        try {
            app(SessionBridge::class)->start($reservation, $f['customer']->id, consentingActor: $f['customer']);
            $this->fail('A rebound source was accepted as the existing session.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('csms_session_links', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_old_account_epoch_cannot_queue_a_new_stop_but_current_account_can_stop(): void
    {
        $f = $this->fixture();
        $this->active($f['session']);
        DB::table('users')->where('id', $f['customer']->id)->increment('auth_epoch');
        try {
            app(SessionBridge::class)->stop($f['session'],
                $f['customer']->id, $f['customer']->role, $f['customer']);
            $this->fail('Old request epoch queued a charger stop.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('charging', $f['session']->fresh()->status);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);

        $fresh = $f['customer']->fresh();
        $result = app(SessionBridge::class)->stop($f['session'], $fresh->id, $fresh->role, $fresh);
        $this->assertSame('stopping', $result['session']->status);
        $this->assertDatabaseCount('charger_commands', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_new_station_owner_cannot_be_impersonated_by_old_vendor_after_transfer(): void
    {
        $f = $this->fixture();
        $this->active($f['session']);
        $replacement = User::factory()->create(['role' => 'vendor']);
        $f['station']->update(['vendor_id' => $replacement->id]);
        try {
            app(SessionBridge::class)->stop($f['session'],
                $f['vendor']->id, $f['vendor']->role, $f['vendor']);
            $this->fail('Former vendor owner stopped a transferred station.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('charger_commands', 1);
        // The reservation's actual customer can still request a safety stop.
        app(SessionBridge::class)->stop($f['session'],
            $f['customer']->id, $f['customer']->role, $f['customer']);
        $this->assertDatabaseCount('charger_commands', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    #[TestWith(['revoked'])]
    #[TestWith(['individual_expiry'])]
    #[TestWith(['global_expiry'])]
    public function test_captured_bearer_cannot_replay_start_stop_or_read_source_receipts_after_revocation(string $reason): void
    {
        $f = $this->fixture();
        $reservation = Reservation::findOrFail($f['session']->reservation_id);
        config(['sanctum.expiration' => 60]);
        $token = $f['customer']->createToken('charging-consent', ['*'], now()->addHours(2))->accessToken;
        $actor = $f['customer']->fresh()->withAccessToken($token);
        $bridge = app(SessionBridge::class);
        $controller = app(CsmsSessionController::class);
        $request = Request::create('/api/v1/charging-sessions/'.$f['session']->id.'/reconcile', 'POST');
        $request->setUserResolver(fn () => $actor);

        $this->assertSame('pending_start', $bridge->start($reservation, $actor->id, consentingActor: $actor)['session']->status);
        $this->assertSame(200, $controller->reconcile($request, $f['session'], $bridge, app(CommandReconciler::class))->getStatusCode());
        match ($reason) {
            'revoked' => $token->delete(),
            'individual_expiry' => $token->forceFill(['expires_at' => now()->subSecond()])->save(),
            'global_expiry' => $token->forceFill(['created_at' => now()->subMinutes(61)])->save(),
        };
        $this->assertSame((int) $actor->auth_epoch, (int) $f['customer']->fresh()->auth_epoch);
        $this->active($f['session']);
        foreach ([
            fn () => $bridge->start($reservation, $actor->id, consentingActor: $actor),
            fn () => $bridge->stop($f['session'], $actor->id, $actor->role, $actor),
            fn () => $controller->reconcile($request, $f['session'], $bridge, app(CommandReconciler::class)),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Stale individual bearer authority was accepted.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame('charging', $f['session']->fresh()->status);
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);

        $replacement = $f['customer']->createToken('replacement', ['*'], now()->addHours(2))->accessToken;
        $fresh = $f['customer']->fresh()->withAccessToken($replacement);
        $this->assertSame('stopping', $bridge->stop($f['session'], $fresh->id, $fresh->role, $fresh)['session']->status);
        $this->assertDatabaseCount('charger_commands', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_reconcile_rechecks_current_epoch_and_private_operator_ownership(): void
    {
        $f = $this->fixture();
        $request = Request::create('/api/v1/charging-sessions/'.$f['session']->id.'/reconcile', 'POST');
        $request->setUserResolver(fn () => $f['customer']);
        DB::table('users')->where('id', $f['customer']->id)->increment('auth_epoch');
        try {
            app(CsmsSessionController::class)->reconcile($request, $f['session'],
                app(SessionBridge::class), app(CommandReconciler::class));
            $this->fail('Old credential viewed the private source command receipt.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('command_source_observations', 0);

        $request->setUserResolver(fn () => $f['vendor']);
        $f['station']->update(['vendor_id' => User::factory()->create(['role' => 'vendor'])->id]);
        try {
            app(CsmsSessionController::class)->reconcile($request, $f['session'],
                app(SessionBridge::class), app(CommandReconciler::class));
            $this->fail('Former station operator received a private source receipt.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $request->setUserResolver(fn () => $f['customer']->fresh());
        $response = app(CsmsSessionController::class)->reconcile($request, $f['session'],
            app(SessionBridge::class), app(CommandReconciler::class));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
