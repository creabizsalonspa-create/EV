<?php

namespace Tests\Postgres;

use App\Services\Commercial\Payments\HostedCheckout;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HostedCheckoutConcurrencyTest extends TestCase
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
        $dir = sys_get_temp_dir().'/hosted-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($inputs as $n => $input) {
                file_put_contents($dir.'/input-'.$n, json_encode($input, JSON_THROW_ON_ERROR));
                $pipes = [];
                $ready = $dir.'/ready-'.$n;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/hosted-checkout-worker.php'), $operation, $dir.'/input-'.$n, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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

    private function input(array $f, string $key): array
    {
        return ['actor' => $f['customer']->id, 'values' => ['scope' => 'charging', 'invoice_id' => $f['invoice']->id, 'idempotency_key' => $key, 'consent' => true]];
    }

    public function test_simultaneous_identical_creation_preserves_one_canonical_identity(): void
    {
        $f = (require base_path('tests/Fixtures/hosted.php'))();
        $input = $this->input($f, 'same-key');
        $results = $this->race('create', [$input, $input]);
        foreach ($results as $r) {
            $this->assertContains($r['status'], [201, 409]);
        }
        $this->assertContains(201, array_column($results, 'status'));
        $this->assertDatabaseCount('hosted_payment_checkouts', 1);
        $this->assertDatabaseCount('gateway_checkouts', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertSame(1, DB::table('hosted_payment_events')->where('kind', 'provider_creation_dispatched')->count());
    }

    public function test_distinct_creation_keys_cannot_open_parallel_hosted_financial_attempts(): void
    {
        $f = (require base_path('tests/Fixtures/hosted.php'))();
        $results = $this->race('create', [$this->input($f, 'key-a'), $this->input($f, 'key-b')]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('hosted_payment_checkouts', 1);
        $this->assertDatabaseCount('gateway_checkouts', 1);
    }

    public function test_parallel_capture_posts_one_payment_and_one_canonical_operation(): void
    {
        $f = (require base_path('tests/Fixtures/hosted.php'))();
        $h = app(HostedCheckout::class)->create($f['customer'], $this->input($f, 'capture')['values']);
        app(HostedCheckout::class)->simulate($f['customer'], $h['id'], 'authorized');
        $input = ['actor' => $f['customer']->id, 'hosted' => $h['id']];
        $results = $this->race('capture', [$input, $input]);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertSame(1, DB::table('gateway_operations')->where('kind', 'capture')->count());
        $this->assertSame('captured', DB::table('hosted_payment_checkouts')->find($h['id'])->status);
    }
}
