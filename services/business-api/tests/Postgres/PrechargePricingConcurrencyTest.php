<?php

namespace Tests\Postgres;

use App\Models\Finance\SessionQuote;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\PrechargePricing;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrechargePricingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Explicit disposable PostgreSQL required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function race(string $operation, array $body, int $actor): array
    {
        $dir = sys_get_temp_dir().'/precharge-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $gate = $dir.'/go';
        $workers = [];
        try {
            foreach ([0, 1] as $index) {
                $ready = $dir.'/ready-'.$index;
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/connector-reservation-worker.php'), base64_encode(json_encode($body, JSON_THROW_ON_ERROR)), (string) $actor, $gate, $ready, $operation], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $until = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $until) {
                usleep(5000);
            }
            $this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($gate);
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = (int) stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_terminate($process);
                proc_close($process);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_duplicate_confirmations_and_starts_capture_one_verified_receipt_and_quote(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Concurrent price fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->subMinute(), 'idempotency_key' => 'concurrent-price', 'reservation_terms_snapshot' => ['price_acceptance_required' => true]]);
        $preview = DB::transaction(fn () => app(PrechargePricing::class)->preview($reservation, $customer->id, 10000));
        $this->assertSame([200, 200], $this->race('price_accept', ['reservation_id' => $reservation->id, 'quote_token' => $preview['quote_token'], 'accepted' => true], $customer->id));
        $this->assertDatabaseCount('charging_price_consents', 1);
        $this->assertSame([200, 201], $this->race('price_start', ['reservation_id' => $reservation->id], $customer->id));
        $this->assertDatabaseCount('charging_sessions', 1);
        $this->assertDatabaseCount('finance_session_quotes', 1);
        $this->assertSame((int) DB::table('charging_price_consents')->value('id'), (int) SessionQuote::firstOrFail()->price_consent_id);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }
}
