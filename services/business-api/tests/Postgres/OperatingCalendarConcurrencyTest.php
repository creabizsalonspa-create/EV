<?php

namespace Tests\Postgres;

use App\Http\Controllers\StationInformationController;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperatingCalendarConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Explicit disposable PostgreSQL required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public static function winners(): array
    {
        return [['book'], ['publish']];
    }

    #[DataProvider('winners')]
    public function test_operating_calendar_and_booking_are_serialized_with_server_owned_evidence(string $first): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Calendar race', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $slot = now('UTC')->addDay()->startOfHour();
        $details = ['revision' => 0, 'enforce_operating_hours' => true, 'description_en' => null, 'description_ar' => null, 'access_en' => null, 'access_ar' => null,
            'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['open' => '00:00', 'close' => '00:00']), 'closed_dates' => [], 'amenities' => [], 'accessibility' => []];
        $request = Request::create('/test', 'PUT', $details);
        $request->setUserResolver(fn () => $vendor);
        app(StationInformationController::class)->save($request, $station);
        $details['revision'] = 1;
        $details['closed_dates'] = [$slot->copy()->setTimezone('Asia/Dubai')->format('Y-m-d')];
        $operations = [
            'book' => ['actor_id' => $customer->id, 'body' => ['station_id' => $station->id, 'starts_at' => $slot->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'calendar-race']],
            'publish' => ['actor_id' => $vendor->id, 'body' => $details],
        ];
        $dir = sys_get_temp_dir().'/calendar-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ([$first, $first === 'book' ? 'publish' : 'book'] as $index => $operation) {
                $input = $operations[$operation] + ['station_id' => $station->id, 'first' => $index === 0, 'operation' => $operation];
                $pipes = [];
                $ready = $dir.'/ready-'.$index;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/operating-calendar-worker.php'), base64_encode(json_encode($input)), $dir.'/start', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $operation];
                // First worker owns its lock before the competing process starts.
                $deadline = microtime(true) + 15;
                while (! is_file($ready) && microtime(true) < $deadline) {
                    usleep(5000);
                }
                $this->assertFileExists($ready);
            }
            touch($dir.'/start');
            $results = [];
            foreach ($workers as [$process, $pipes, $operation]) {
                $out = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $results[$operation] = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
            }
            $workers = [];
            $this->assertSame(200, $results['publish']['status']);
            $this->assertSame(2, $results['publish']['body']['revision']);
            $this->assertSame($first === 'book' ? 201 : 409, $results['book']['status']);
            $this->assertSame($first === 'book' ? 1 : 0, Reservation::count());
            if ($first === 'book') {
                $this->assertSame(1, Reservation::sole()->operating_calendar_snapshot['revision']);
                $this->assertSame([], Reservation::sole()->operating_calendar_snapshot['closed_dates']);
            }
            $this->assertDatabaseCount('charging_sessions', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
        } finally {
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
