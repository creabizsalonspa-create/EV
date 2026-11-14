<?php

namespace Tests\Postgres;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\ScheduledSync;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OcpiScheduledSyncConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Disposable PostgreSQL only.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_review', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function race(string $operation, string $id = 'none'): array
    {
        $dir = sys_get_temp_dir().'/ocpi-sync-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ([0, 1] as $i) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $p = proc_open([PHP_BINARY, base_path('tests/Support/ocpi-scheduled-sync-worker.php'), $operation, $id, $dir.'/gate', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($p);
                $workers[] = [$p, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($workers[0][2]) || ! is_file($workers[1][2])) && microtime(true) < $deadline) {
                usleep(5000);
            }$this->assertFileExists($workers[0][2]);
            $this->assertFileExists($workers[1][2]);
            touch($dir.'/gate');
            $results = [];
            foreach ($workers as [$p,$pipes]) {
                $results[] = json_decode(stream_get_contents($pipes[1]), true, 16, JSON_THROW_ON_ERROR);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $err);
            }$workers = [];

            return $results;
        } finally {
            foreach ($workers as [$p,$pipes]) {
                proc_terminate($p);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }proc_close($p);
            }foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }

    public function test_independent_workers_claim_one_partner_module_without_overlapping_provider_reads(): void
    {
        config(['ocpi.scheduled_sync_enabled' => true, 'ocpi.connections.race' => ['origin' => 'https://partner.example', 'authorization_token' => base64_encode('synthetic-read-only'), 'party_country' => 'AE', 'party_id' => 'ABC', 'party_role' => 'CPO']]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Race fixture', 'connection_key' => 'race', 'authorized' => true, 'version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations', 'tariffs' => 'https://partner.example/tariffs']]);
        app(ScheduledSync::class)->update($owner, $provider, 'locations', true, 15, 0);
        app(ScheduledSync::class)->update($owner, $provider, 'tariffs', true, 15, 0);
        $results = $this->race('poll');
        $states = array_column($results, 'result');
        sort($states);
        $this->assertSame(['completed', 'idle'], $states);
        $this->assertSame(1, array_sum(array_column($results, 'requests')));
        $this->assertDatabaseCount('ocpi_sync_runs', 1);
        $this->assertSame('completed', DB::table('ocpi_sync_runs')->value('state'));
        $this->assertSame(0, DB::table('ocpi_sync_plans')->whereNotNull('active_run')->count());
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }
}
