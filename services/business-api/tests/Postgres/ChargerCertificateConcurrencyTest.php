<?php

namespace Tests\Postgres;

use App\Models\Charging\AdmissionProfile;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\ChargerCertificates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesChargerCertificate;
use Tests\TestCase;

class ChargerCertificateConcurrencyTest extends TestCase
{
    use CreatesChargerCertificate;

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

    #[TestWith(['same'])]
    #[TestWith(['competing'])]
    #[TestWith(['cancel'])]
    #[TestWith(['rotate'])]
    #[TestWith(['revoke_rotate'])]
    public function test_independent_workers_preserve_one_version_and_no_admission_replay(string $kind): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Certificate PG', 'country' => 'AE', 'trade_license_number' => 'PG', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'name' => 'PG', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'PG', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CERT-PG', 'manufacturer' => 'Synthetic', 'model' => 'PG', 'source' => 'simulator', 'ocpp_protocol' => '2.0.1']);
        $profile = AdmissionProfile::create(['charger_id' => $charger->id, 'identity' => 'ev-test-certificate', 'protocol' => '2.0.1']);
        [$pem] = $this->certificatePair();
        $intent = ['idempotency_key' => (string) Str::uuid(), 'expected_actor_id' => $owner->id, 'expected_profile_id' => $profile->id, 'expected_version' => 0, 'reason' => 'Explicit concurrent certificate policy request', 'consent' => true, 'public_certificate' => $pem];
        $operations = ['enroll', $kind === 'cancel' ? 'cancel' : ($kind === 'rotate' ? 'credential' : 'enroll')];
        if ($kind === 'revoke_rotate') {
            $receipt = app(ChargerCertificates::class)->apply($owner, $company, $charger, 'enroll', $intent);
            unset($intent['public_certificate']);
            $intent['certificate_id'] = $receipt['certificate_id'];
            $intent['expected_version'] = 1;
            $intent['idempotency_key'] = (string) Str::uuid();
            $operations = ['revoke', 'credential'];
        }
        DB::unprepared('CREATE FUNCTION certificate_race_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.3); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER certificate_race_delay BEFORE INSERT ON charger_admission_events FOR EACH ROW EXECUTE FUNCTION certificate_race_delay()');
        $directory = sys_get_temp_dir().'/charger-cert-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($operations as $index => $operation) {
                $body = $intent;
                if ($index === 1 && $kind === 'competing') {
                    $body['idempotency_key'] = (string) Str::uuid();
                }
                $input = $directory.'/input-'.$index;
                file_put_contents($input, json_encode(['actor' => $owner->id, 'company' => $company->id, 'charger' => $charger->id, 'operation' => $operation, 'intent' => $body, 'bundle' => config('csms.certificate_issuer_bundle'), 'pins' => config('csms.certificate_issuer_pins')], JSON_THROW_ON_ERROR));
                chmod($input, 0600);
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/certificate-concurrency-worker.php'), $input, $directory.'/start', $directory.'/ready-'.$index], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            sort($statuses);
            $p = $profile->fresh();
            if ($kind === 'same') {
                $this->assertSame(['200', '200'], $statuses);
            } elseif ($kind === 'cancel') {
                $this->assertSame($p->managed_certificate_id ? ['200', '200'] : ['200', '409'], $statuses);
            } else {
                $this->assertContains('409', $statuses);
                $this->assertTrue(in_array('200', $statuses, true) || in_array('201', $statuses, true));
            }
            $this->assertSame($kind === 'revoke_rotate' ? 2 : ($kind === 'cancel' && ! $p->managed_certificate_id ? 0 : 1), $p->credential_version);
            $this->assertDatabaseCount('charger_certificates', $p->managed_certificate_id ? 1 : 0);
            $this->assertDatabaseCount('charger_commands', 0);
            $this->assertDatabaseCount('finance_journals', 0);
            if ($kind === 'same' || $kind === 'competing') {
                $this->assertFalse($p->enabled);
                $this->assertNull($p->credential_digest);
                $this->assertDatabaseCount('charger_certificate_actions', 1);
            }
            if ($kind === 'revoke_rotate') {
                $revoked = DB::table('charger_certificate_actions')->where('action', 'revoke')->exists();
                $this->assertSame(! $revoked, $p->enabled);
                if ($revoked) {
                    $this->assertNull($p->credential_digest);
                }
            }
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                } proc_close($process);
            }
            DB::unprepared('DROP TRIGGER IF EXISTS certificate_race_delay ON charger_admission_events');
            DB::unprepared('DROP FUNCTION IF EXISTS certificate_race_delay()');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }
}
