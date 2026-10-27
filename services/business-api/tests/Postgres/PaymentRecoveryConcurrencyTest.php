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

class PaymentRecoveryConcurrencyTest extends TestCase
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
        config(['payments.provider' => 'stripe_test']);
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    private function fixture(): array
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $user = User::factory()->create();
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Durable recovery race', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $reservation = Reservation::create(['user_id' => $user->id, 'station_id' => $station->id, 'starts_at' => now(), 'status' => 'confirmed', 'idempotency_key' => 'recovery-race']);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'stopped', 'energy_wh' => 1000, 'rate_minor_per_kwh' => 100, 'currency' => 'AED', 'total_minor' => 100, 'stopped_at' => now()]);
        $invoice = app(FinanceService::class)->issue($session, $user->id);
        $checkout = app(GatewayService::class)->checkout($invoice, $user->id, 100, 'recovery-race');
        DB::table('gateway_checkouts')->where('id', $checkout->id)->update(['provider_reference' => 'pi_recovery', 'status' => 'authorized', 'authorization_expires_at' => now()->addHour()]);

        return [$user, $invoice, $checkout];
    }

    private function start(object $checkout, int $actor, string $mode, string $directory, string $clientKey): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, base_path('tests/Support/payment-recovery-worker.php'), (string) $checkout->id, (string) $actor, $mode, $directory.'/keys', $directory.'/started', $clientKey], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $this->assertSame('', $error, 'Worker must not leak connection or credential diagnostics.');

        return [$status, $out];
    }

    private function cleanup(string $directory, array $workers = []): void
    {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    public function test_actual_process_crash_preserves_committed_intent_and_retry_reuses_provider_key(): void
    {
        [$user, $invoice, $checkout] = $this->fixture();
        $directory = sys_get_temp_dir().'/recovery-crash-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            $workers[] = $this->start($checkout, $user->id, 'crash', $directory, 'original-client-key');
            $this->assertSame([72, ''], $this->finish($workers[0]));
            $workers = [];
            $intent = DB::table('payment_recovery_intents')->sole();
            $this->assertSame('executing', $intent->status);
            $this->assertDatabaseCount('finance_payments', 0);
            $this->assertSame('authorized', DB::table('gateway_checkouts')->find($checkout->id)->status);
            DB::table('payment_recovery_intents')->where('id', $intent->id)->update(['lease_until' => now()->subSecond()]);
            $workers[] = $this->start($checkout, $user->id, 'normal', $directory, 'replacement-client-key');
            $this->assertSame([0, '200'], $this->finish($workers[0]));
            $workers = [];
            $keys = file($directory.'/keys', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertCount(2, $keys);
            $this->assertSame($keys[0], $keys[1]);
            $this->assertSame(hash('sha256', 'creabiz-test:recovery:'.$intent->id), $keys[0]);
            $this->assertSame('completed', DB::table('payment_recovery_intents')->sole()->status);
            $this->assertDatabaseCount('finance_payments', 1);
            $this->assertSame(1, DB::table('finance_journals')->where('invoice_id', $invoice->id)->where('kind', 'payment')->count());
            $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        } finally {
            $this->cleanup($directory, $workers);
        }
    }

    public function test_overlapping_provider_calls_are_rejected_by_lease_and_commit_one_capture(): void
    {
        [$user, $invoice, $checkout] = $this->fixture();
        $directory = sys_get_temp_dir().'/recovery-concurrent-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            $workers[] = $this->start($checkout, $user->id, 'slow', $directory, 'first-worker');
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/started') && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($directory.'/started');
            $workers[] = $this->start($checkout, $user->id, 'normal', $directory, 'second-worker');
            $second = $this->finish($workers[1]);
            unset($workers[1]);
            touch($directory.'/started.release');
            $first = $this->finish($workers[0]);
            $workers = [];
            $this->assertSame([0, '200'], $first);
            $this->assertSame([0, '409'], $second);
            $this->assertCount(1, file($directory.'/keys', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $this->assertDatabaseCount('finance_payments', 1);
            $this->assertDatabaseCount('payment_recovery_intents', 1);
            $this->assertTrue(app(FinanceService::class)->summary($invoice)['ledger_balanced']);
        } finally {
            $this->cleanup($directory, $workers);
        }
    }
}
