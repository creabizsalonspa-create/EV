<?php

namespace Tests\Postgres;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ChargerSourceRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ChargerSourceRegistrationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Dedicated disposable PostgreSQL required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['csms.upstream_registration_enabled' => true, 'csms.upstream_registration_allow_local_http' => true, 'csms.graphql_url' => 'http://127.0.0.1:8095/v1/graphql', 'csms.graphql_secret' => str_repeat('s', 64)]);
    }

    #[TestWith(['1.6', 'prepare'])]
    #[TestWith(['2.0.1', 'prepare'])]
    #[TestWith(['1.6', 'register'])]
    #[TestWith(['2.0.1', 'register'])]
    public function test_two_processes_converge_on_one_mapping_receipt_and_source_write(string $protocol, string $mode): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $actor = User::factory()->create(['role' => 'admin']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Concurrent source fixture', 'country' => 'AE', 'trade_license_number' => 'CONCURRENT', 'license_expires_on' => now()->addYear(), 'status' => 'approved']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Concurrent direct metadata', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'direct_unverified', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Concurrent site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'SOURCE', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'direct_unverified', 'ocpp_protocol' => $protocol]);
        $registration = null;
        if ($mode === 'register') {
            $request = Request::create('/owned/source-registration', 'POST');
            $request->setUserResolver(fn () => $actor);
            $registration = app(ChargerSourceRegistration::class)->prepare($request, $company, $charger, ['identity' => 'CB-CONCURRENT-001', 'idempotency_key' => 'same-source-request'])['registration_id'];
        }
        $directory = sys_get_temp_dir().'/source-registration-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/counter', '0');
        $workers = [];
        try {
            foreach ([0, 1] as $i) {
                $payload = ['mode' => $mode, 'actor' => $actor->id, 'company' => $company->id, 'charger' => $charger->id, 'registration' => $registration, 'counter' => $directory.'/counter'];
                $file = $directory.'/worker-'.$i.'.json';
                file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));
                chmod($file, 0600);
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/source-registration-concurrency-worker.php'), $file, $directory.'/start', $directory.'/ready-'.$i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            touch($directory.'/start');
            $outcomes = [];
            foreach ($workers as [$process, $pipes]) {
                $outcomes[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            if ($mode === 'prepare') {
                sort($outcomes);
                $this->assertSame(['200', '201'], $outcomes);
            } else {
                foreach ($outcomes as $outcome) {
                    $this->assertContains($outcome, ['registering', 'registered']);
                }
                $this->assertDatabaseHas('charger_source_registrations', ['id' => $registration, 'state' => 'registered', 'source_station_id' => 501]);
            }
            $this->assertSame($mode === 'register' ? '1' : '0', file_get_contents($directory.'/counter'));
            $this->assertDatabaseCount('charger_source_registrations', 1);
            $this->assertDatabaseCount('csms_bindings', 1);
            $this->assertDatabaseCount('charger_admission_profiles', 1);
            $this->assertDatabaseHas('charger_admission_profiles', ['enabled' => false, 'credential_digest' => null]);
            $this->assertDatabaseCount('charger_commands', 0);
            $this->assertDatabaseCount('csms_inbox_events', 0);
            $this->assertDatabaseCount('finance_invoices', 0);
            $this->assertDatabaseCount('finance_journals', 0);
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
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
