<?php

namespace Tests\Postgres;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExternalDisputeConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private disposableMigrations; }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->disposableMigrations();
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires disposable PostgreSQL testing database.');
        }
        parent::setUp();
    }

    private function race(array $events): void
    {
        $fixture = (require base_path('tests/Fixtures/external-dispute.php'))();
        $dir = sys_get_temp_dir().'/dispute-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        file_put_contents($dir.'/body.json', json_encode($fixture['body'], JSON_THROW_ON_ERROR));
        $workers = [];
        try {
            foreach ($events as $index => $event) {
                $pipes = [];
                $ready = $dir.'/ready-'.$index;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/external-dispute-worker.php'), $dir.'/body.json', $event, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $stderr.$stdout);
                $this->assertSame('ok', $stdout);
            }
            $this->assertDatabaseCount('external_disputes', 1);
            $this->assertDatabaseCount('gateway_cases', 1);
            $this->assertDatabaseCount('external_dispute_events', count(array_unique($events)));
            $this->assertDatabaseHas('gateway_cases', ['status' => 'open']);
            $this->assertDatabaseCount('finance_refunds', 0);
        } finally {
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_duplicate_callbacks_serialize_to_one_dispute_and_one_hold(): void
    {
        $this->race(['evt_Race', 'evt_Race']);
    }

    public function test_distinct_provider_events_share_one_canonical_invoice_hold(): void
    {
        $this->race(['evt_RaceOne', 'evt_RaceTwo']);
    }
}
