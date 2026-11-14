<?php

namespace Tests\Postgres;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class VendorAgreementConcurrencyTest extends TestCase
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

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_independent_administrators_and_companies_serialize_one_country_publication(bool $competing): void
    {
        DB::unprepared('CREATE OR REPLACE FUNCTION agreement_race_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.25); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER agreement_race_delay BEFORE INSERT ON vendor_agreement_versions FOR EACH ROW EXECUTE FUNCTION agreement_race_delay()');
        $directory = sys_get_temp_dir().'/agreement-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $admin = User::factory()->create(['role' => 'admin']);
                $owner = User::factory()->create(['role' => 'vendor']);
                $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic company', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'draft']);
                $values = ['password' => 'password', 'expected_revision' => 0, 'title_en' => 'Synthetic terms', 'title_ar' => 'شروط تجريبية', 'body_en' => 'Synthetic terms for software concurrency validation only.', 'body_ar' => 'شروط تجريبية لاختبار تزامن البرمجيات فقط.', 'publication_reference' => 'SYNTHETIC-ONLY', 'approved_for_publication' => true];
                if ($competing && $i === 1) {
                    $values['body_en'] .= ' A competing text.';
                }
                $input = $directory.'/input-'.$i;
                file_put_contents($input, json_encode(['actor' => $admin->id, 'company' => $company->id, 'values' => $values], JSON_THROW_ON_ERROR));
                chmod($input, 0600);
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/agreement-concurrency-worker.php'), $input, $directory.'/go', $directory.'/ready-'.$i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(5000);
            }
            $this->assertFileExists($directory.'/ready-0');
            $this->assertFileExists($directory.'/ready-1');
            touch($directory.'/go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $results[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            }
            $workers = [];
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame($competing ? [201, 409] : [200, 201], $statuses);
            if (! $competing) {
                $this->assertSame($results[0]['body']['version_id'], $results[1]['body']['version_id']);
            }
            $this->assertSame(1, DB::table('vendor_agreement_versions')->count());
            $this->assertSame(1, DB::table('vendor_agreement_channels')->value('revision'));
            $this->assertSame(1, DB::table('audit_events')->where('action', 'vendor_agreement.published')->count());
            $this->assertSame(0, DB::table('vendor_agreement_acceptances')->count());
            $this->assertSame(0, DB::table('charger_commands')->count());
            $this->assertSame(0, DB::table('finance_payments')->count());
        } finally {
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
    }
}
