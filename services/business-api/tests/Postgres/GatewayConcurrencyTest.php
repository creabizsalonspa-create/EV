<?php

namespace Tests\Postgres;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Commercial\Payments\GatewayService;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GatewayConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateDisposableDatabase;
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires a disposable PostgreSQL testing database.');
        }
        parent::setUp();
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    private function fixture(bool $competing = false): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $user = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Gateway race', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $reservation = Reservation::create(['user_id' => $user->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'gateway-race']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
        $invoice = app(FinanceService::class)->issue($session, $user->id);
        $gateway = app(GatewayService::class);
        $first = $gateway->checkout($invoice, $user->id, 100, 'first');
        $gateway->operate($first->id, $user->id, 'authorize', 'auth', null, 'mock_success');
        $second = $first;
        if ($competing) {
            $second = $gateway->checkout($invoice, $user->id, 100, 'second');
            $gateway->operate($second->id, $user->id, 'authorize', 'auth', null, 'mock_success');
        }

        return [$user, $invoice, [$first, $second]];
    }

    private function race(array $checkouts, int $actor, string $operation): array
    {
        $directory = sys_get_temp_dir().'/gateway-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($checkouts as $index => $checkout) {
                $pipes = [];
                $ready = $directory.'/ready-'.$index;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/gateway-concurrency-worker.php'), (string) $checkout->id, (string) $actor, $directory.'/start', $ready, $operation], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $worker) {
                $this->assertFileExists($worker[2]);
            }
            touch($directory.'/start');
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_simultaneous_capture_retries_post_exactly_once(): void
    {
        [$user, $invoice, $checkouts] = $this->fixture();
        $this->assertSame(['200', '200'], $this->race($checkouts, $user->id, 'capture'));
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('gateway_operations', 2);
        $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
    }

    public function test_competing_authorizations_cannot_double_capture_invoice(): void
    {
        [$user, $invoice, $checkouts] = $this->fixture(true);
        $this->assertSame(['200', '409'], $this->race($checkouts, $user->id, 'capture'));
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertSame(100, app(FinanceService::class)->summary($invoice)['net_collected_minor']);
    }

    public function test_simultaneous_signed_webhook_retries_commit_one_event_and_one_payment(): void
    {
        [$user, $invoice, $checkouts] = $this->fixture();
        $this->assertSame(['200', '200'], $this->race($checkouts, $user->id, 'webhook'));
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('gateway_webhook_events', 1);
        $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
    }
}
