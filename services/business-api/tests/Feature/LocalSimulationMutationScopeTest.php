<?php

namespace Tests\Feature;

use App\Http\Controllers\SimulationController;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalSimulationMutationScopeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create([
            'vendor_id' => $vendor->id, 'name' => 'Local simulation scope',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $reservation = Reservation::create([
            'user_id' => $customer->id, 'station_id' => $station->id,
            'starts_at' => now()->subMinutes(5), 'idempotency_key' => 'local-scope',
        ]);
        $session = ChargingSession::create([
            'reservation_id' => $reservation->id, 'rate_minor_per_kwh' => 125,
            'currency' => 'AED', 'energy_wh' => 1000,
        ]);

        return compact('vendor', 'customer', 'station', 'reservation', 'session');
    }

    private function request(User $actor, array $data = []): Request
    {
        $request = Request::create('/simulation', 'POST', $data);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    private function rejects(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('An invalid local simulator mutation was accepted.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_stale_customer_cannot_replay_start_or_stop_after_reservation_transfer(): void
    {
        $f = $this->fixture();
        $controller = app(SimulationController::class);
        $request = $this->request($f['customer']);
        $replacement = User::factory()->create(['role' => 'customer']);
        DB::table('reservations')->where('id', $f['reservation']->id)->update(['user_id' => $replacement->id]);

        $this->rejects(fn () => $controller->start($request, $f['reservation']), 404);
        $this->rejects(fn () => $controller->stop($request, $f['session']), 404);
        $this->assertSame('charging', $f['session']->fresh()->status);
        $this->assertDatabaseCount('charging_sessions', 1);
        $this->assertDatabaseCount('finance_invoices', 0);

        $stopped = $controller->stop($this->request($replacement), $f['session']);
        $this->assertSame('stopped', $stopped->status);
        $this->assertSame(125, (int) $stopped->total_minor);
    }

    public function test_current_vendor_and_local_source_are_required_for_meter_updates(): void
    {
        $f = $this->fixture();
        $controller = app(SimulationController::class);
        $oldRequest = $this->request($f['vendor'], ['energy_wh' => 2500]);
        $replacement = User::factory()->create(['role' => 'vendor']);
        $f['station']->update(['vendor_id' => $replacement->id]);
        $this->rejects(fn () => $controller->meter($oldRequest, $f['session']), 404);

        $currentRequest = $this->request($replacement, ['energy_wh' => 2500]);
        $f['station']->update(['source' => 'partner']);
        $this->rejects(fn () => $controller->meter($currentRequest, $f['session']), 409);
        $this->rejects(fn () => $controller->stop($this->request($f['customer']), $f['session']), 409);
        $this->assertSame(1000, (int) $f['session']->fresh()->energy_wh);
        $this->assertSame('charging', $f['session']->fresh()->status);

        $f['station']->update(['source' => 'simulator']);
        $updated = $controller->meter($currentRequest, $f['session']);
        $this->assertSame(2500, (int) $updated->energy_wh);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_revoked_account_epoch_cannot_change_local_energy_or_terminal_state(): void
    {
        $f = $this->fixture();
        $controller = app(SimulationController::class);
        DB::table('users')->whereIn('id', [$f['vendor']->id, $f['customer']->id])->increment('auth_epoch');

        $this->rejects(fn () => $controller->meter($this->request($f['vendor'], ['energy_wh' => 3000]), $f['session']), 409);
        $this->rejects(fn () => $controller->stop($this->request($f['customer']), $f['session']), 409);
        $this->assertSame(1000, (int) $f['session']->fresh()->energy_wh);
        $this->assertSame('charging', $f['session']->fresh()->status);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
