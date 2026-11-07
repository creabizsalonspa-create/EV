<?php

namespace Tests\Postgres;

use App\Services\Commercial\Payments\CorporateHostedCollection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorporateHostedConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires disposable PostgreSQL.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_review', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config(['payments.provider' => 'mock']);
    }

    private function race(string $operation, array $inputs): array
    {
        $dir = sys_get_temp_dir().'/corporate-hosted-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($inputs as $n => $input) {
                file_put_contents($dir.'/input-'.$n, json_encode($input, JSON_THROW_ON_ERROR));
                $pipes = [];
                $ready = $dir.'/ready-'.$n;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/corporate-hosted-worker.php'), $operation, $dir.'/input-'.$n, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($workers, fn ($w) => is_file($w[2]))) !== count($workers) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $worker) {
                $this->assertFileExists($worker[2]);
            }
            touch($dir.'/go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $err.$out);
                $results[] = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
            }
            $workers = [];

            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                } proc_terminate($process);
                proc_close($process);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_simultaneous_creation_preserves_one_original_statement_identity(): void
    {
        $f = (require base_path('tests/Fixtures/corporate-hosted.php'))();
        $input = ['actor' => $f['owner']->id, 'statement' => $f['statement']->id, 'key' => 'same'];
        $results = $this->race('create', [$input, $input]);
        foreach ($results as $r) {
            $this->assertContains($r['status'], [201, 409]);
        }
        $this->assertContains(201, array_column($results, 'status'));
        $this->assertDatabaseCount('corporate_hosted_checkouts', 1);
        $this->assertDatabaseCount('corporate_hosted_lines', 2);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_competing_driver_payment_and_statement_preparation_share_invoice_root_holds(): void
    {
        $f = (require base_path('tests/Fixtures/corporate-hosted.php'))();
        $results = $this->race('compete', [
            ['actor' => $f['owner']->id, 'statement' => $f['statement']->id, 'key' => 'corporate'],
            ['actor' => $f['driver']->id, 'invoice' => $f['invoices'][0]->id, 'key' => 'driver'],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        if (DB::table('corporate_hosted_checkouts')->exists()) {
            $this->assertDatabaseCount('finance_payments', 0);
        } else {
            $this->assertDatabaseCount('finance_payments', 1);
        }
    }

    public function test_parallel_corporate_capture_allocates_each_invoice_once(): void
    {
        $f = (require base_path('tests/Fixtures/corporate-hosted.php'))();
        $service = app(CorporateHostedCollection::class);
        $h = $service->create($f['owner'], $f['statement']->id, 'capture');
        $service->simulate($f['owner'], $h['id'], 'authorized');
        $input = ['actor' => $f['owner']->id, 'hosted' => $h['id']];
        $results = $this->race('capture', [$input, $input]);
        foreach ($results as $r) {
            $this->assertContains($r['status'], [201, 409]);
        }
        $this->assertContains(201, array_column($results, 'status'));
        $this->assertDatabaseCount('finance_payments', 2);
        $this->assertDatabaseCount('corporate_receipts', 1);
        $this->assertDatabaseCount('corporate_receipt_payments', 2);
        $this->assertSame('captured', DB::table('corporate_hosted_checkouts')->find($h['id'])->status);
    }
}
