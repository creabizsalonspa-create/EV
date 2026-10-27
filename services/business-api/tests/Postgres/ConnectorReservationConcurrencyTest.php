<?php

namespace Tests\Postgres;

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConnectorReservationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Explicit disposable PostgreSQL required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        // No down migration may collapse historical parallel bookings. Only this
        // guarded disposable test database is recreated, then destroyed by runner.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public static function races(): array
    {
        return [['same-evse', ['201', '409'], 1], ['different-evse', ['201', '201'], 2], ['identical-retry', ['200', '201'], 1], ['changed-key', ['201', '409'], 1]];
    }

    #[DataProvider('races')]
    public function test_concurrent_reservations_share_evse_capacity_and_replay_safely(string $kind, array $expected, int $rows): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'PG canonical', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Synthetic', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'PG-CANONICAL', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator']);
        $evses = [];
        for ($i = 1; $i <= 2; $i++) {
            $evses[] = Evse::create(['charger_id' => $charger->id, 'number' => $i, 'mode' => 'application']);
        }
        $maps = [];
        for ($i = 1; $i <= 3; $i++) {
            $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => $i, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
            $maps[] = ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evses[$i === 3 ? 1 : 0]->id, 'connector_id' => $connector->id]);
        }
        $body = ['station_id' => $station->id, 'connector_mapping_id' => $maps[0]->id, 'starts_at' => now()->startOfHour()->addHours(2)->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'race-a'];
        $other = $body;
        if ($kind === 'same-evse') {
            $other['connector_mapping_id'] = $maps[1]->id;
            $other['idempotency_key'] = 'race-b';
        }
        if ($kind === 'different-evse') {
            $other['connector_mapping_id'] = $maps[2]->id;
            $other['idempotency_key'] = 'race-b';
        }
        if ($kind === 'changed-key') {
            $other['connector_mapping_id'] = $maps[2]->id;
        }
        DB::unprepared('CREATE FUNCTION connector_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER connector_delay BEFORE INSERT ON reservations FOR EACH ROW EXECUTE FUNCTION connector_delay()');
        $dir = sys_get_temp_dir().'/connector-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $gate = $dir.'/start';
        $workers = [];
        try {
            foreach ([$body, $other] as $i => $payload) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/connector-reservation-worker.php'), base64_encode(json_encode($payload)), (string) $customer->id, $gate, $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($gate);
            $statuses = [];
            foreach ($workers as [$process,$pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($statuses);
            $this->assertSame($expected, $statuses);
            $this->assertDatabaseCount('reservations', $rows);
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertSame($rows, DB::table('audit_events')->where('action', 'reservation.created')->count());
        } finally {
            foreach ($workers as [$process,$pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }foreach ($pipes as $p) {
                    if (is_resource($p)) {
                        fclose($p);
                    }
                }if (is_resource($process)) {
                    proc_close($process);
                }
            }
            foreach (glob($dir.'/*') as $p) {
                unlink($p);
            }rmdir($dir);
            DB::unprepared('DROP TRIGGER IF EXISTS connector_delay ON reservations');
            DB::unprepared('DROP FUNCTION IF EXISTS connector_delay()');
        }
    }
}
