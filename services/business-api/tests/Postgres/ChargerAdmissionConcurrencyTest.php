<?php

namespace Tests\Postgres;

use App\Models\ChargerCommand;
use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChargerAdmissionConcurrencyTest extends TestCase
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

    public function test_two_companies_cannot_race_to_claim_the_same_charger_identity(): void
    {
        DB::unprepared('CREATE FUNCTION admission_namespace_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.5); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER admission_namespace_delay BEFORE INSERT ON charger_admission_profiles FOR EACH ROW EXECUTE FUNCTION admission_namespace_delay()');
        $dir = sys_get_temp_dir().'/admission-namespace-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $gate = $dir.'/start';
        $workers = [];
        try {
            foreach ([0, 1] as $index) {
                $owner = User::factory()->create(['role' => 'vendor']);
                $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Namespace PG', 'country' => 'AE', 'trade_license_number' => 'NS-'.$index, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
                $site = VendorSite::create(['company_id' => $company->id, 'name' => 'PG', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'PG', 'latitude' => 25, 'longitude' => 55]);
                $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'NS-'.$index, 'manufacturer' => 'PG', 'model' => 'PG', 'source' => 'simulator', 'ocpp_protocol' => '1.6']);
                $body = base64_encode(json_encode(['company_id' => $company->id, 'charger_id' => $charger->id, 'identity' => 'ev-test-concurrent-identity']));
                $ready = $dir.'/ready-'.$index;
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/connector-reservation-worker.php'), $body, (string) $owner->id, $gate, $ready, 'configure'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($results);
            $this->assertSame(['201', '409'], $results);
            $this->assertDatabaseCount('charger_admission_profiles', 1);
            $this->assertDatabaseCount('charger_admission_events', 1);
            $this->assertFalse(AdmissionProfile::firstOrFail()->enabled);
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
            DB::unprepared('DROP TRIGGER IF EXISTS admission_namespace_delay ON charger_admission_profiles');
            DB::unprepared('DROP FUNCTION IF EXISTS admission_namespace_delay()');
        }
    }

    public static function operations(): array
    {
        return [['credential', 'credential'], ['case', 'case'], ['credential', 'revoke']];
    }

    #[DataProvider('operations')]
    public function test_concurrent_credential_rotation_and_recovery_opening_are_serialized(string $operation, string $other): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Lifecycle PG', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'PG admission', 'country' => 'AE', 'trade_license_number' => 'PG', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'PG', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'PG', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'PG', 'manufacturer' => 'PG', 'model' => 'PG', 'source' => 'simulator', 'ocpp_protocol' => '1.6']);
        $profile = AdmissionProfile::create(['charger_id' => $charger->id, 'identity' => 'ev-test-pg-admission', 'protocol' => '1.6']);
        $command = ChargerCommand::create(['actor_id' => $owner->id, 'station_id' => $station->id, 'action' => 'start', 'payload' => [], 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => 'PG', 'status' => 'outcome_unknown']);
        $table = $operation === 'credential' ? 'charger_admission_events' : 'charging_operational_case_events';
        DB::unprepared('CREATE FUNCTION admission_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(1); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER admission_delay BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION admission_delay()');
        $dir = sys_get_temp_dir().'/charger-admission-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $gate = $dir.'/start';
        $workers = [];
        try {
            foreach ([$operation, $other] as $i => $workerOperation) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $body = base64_encode(json_encode(['company_id' => $company->id, 'charger_id' => $charger->id, 'command_id' => $command->id, 'expected_version' => 0, 'days' => 1, 'note' => 'Investigating missing source evidence']));
                $p = proc_open([PHP_BINARY, base_path('tests/Support/connector-reservation-worker.php'), $body, (string) $owner->id, $gate, $ready, $workerOperation], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($p);
                $workers[] = [$p, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($gate);
            $results = [];
            foreach ($workers as [$p,$pipes]) {
                $results[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $error);
            }$workers = [];
            sort($results);
            if ($other === 'revoke') {
                $this->assertContains($results, [['200', '201'], ['200', '409']]);
                $this->assertFalse($profile->fresh()->enabled);
                $this->assertNull($profile->fresh()->credential_digest);
                $this->assertNotNull($profile->fresh()->revoked_at);
                $this->assertDatabaseHas('charger_admission_events', ['profile_id' => $profile->id, 'action' => 'revoked']);

                return;
            }
            $this->assertSame($operation === 'credential' ? ['201', '409'] : ['200', '201'], $results);
            $this->assertDatabaseCount($table, 1);
            if ($operation === 'credential') {
                $this->assertSame(1, $profile->fresh()->credential_version);
                $this->assertTrue($profile->fresh()->enabled);
            } else {
                $this->assertDatabaseCount('charging_operational_cases', 1);
                $this->assertSame('outcome_unknown', $command->fresh()->status);
            }
            $this->assertDatabaseCount('charging_sessions', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
        } finally {
            foreach ($workers as [$p,$pipes]) {
                if (is_resource($p)) {
                    proc_terminate($p);
                }foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }if (is_resource($p)) {
                    proc_close($p);
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
            DB::unprepared('DROP TRIGGER IF EXISTS admission_delay ON '.$table);
            DB::unprepared('DROP FUNCTION IF EXISTS admission_delay()');
        }
    }
}
