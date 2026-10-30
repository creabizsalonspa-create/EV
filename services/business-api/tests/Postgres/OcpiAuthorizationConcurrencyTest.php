<?php

namespace Tests\Postgres;

use App\Http\Controllers\OcpiController;
use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\PartnerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiAuthorizationConcurrencyTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Disposable PostgreSQL testing database required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_review', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->admin = User::factory()->create(['role' => 'admin']);
        config(['ocpi.connections.boundary' => ['origin' => 'https://partner.example', 'authorization_token' => base64_encode('fake-contract-only')]]);
        Http::preventStrayRequests();
    }

    private function provider(): OcpiProvider
    {
        return OcpiProvider::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'PG authorization fixture', 'connection_key' => 'boundary', 'authorized' => true]);
    }

    private function authorize(OcpiProvider $provider, bool $authorized): void
    {
        $request = Request::create('/api/ocpi/providers/'.$provider->id.'/authorization', 'PATCH', ['authorized' => $authorized]);
        $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(200, app(OcpiController::class)->authorization($request, $provider, app(PartnerClient::class))->getStatusCode());
    }

    /** Pause independent workers at the HTTP response or captured ingestion snapshot. */
    private function paused(array $operations, OcpiProvider $provider, callable $during): array
    {
        $directory = sys_get_temp_dir().'/ocpi-generation-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($operations as $index => $operation) {
                $pipes = [];
                $ready = $directory.'/ready-'.$index;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ocpi-boundary-worker.php'), $operation, (string) $provider->id, $directory.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($workers, fn ($worker) => is_file($worker[2]))) !== count($workers) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $worker) {
                $this->assertFileExists($worker[2]);
            }
            $during();
            touch($directory.'/go');
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

            return $results;
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
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_reauthorization_while_http_is_in_flight_discards_stale_negotiation(): void
    {
        $provider = $this->provider();
        $this->assertSame(['409'], $this->paused(['negotiate'], $provider, function () use ($provider) {
            $this->authorize($provider, false);
            $this->authorize($provider, true);
        }));
        $this->assertSame(2, $provider->fresh()->authorization_revision);
        $this->assertNull($provider->fresh()->version);
        $this->assertNull($provider->fresh()->endpoints);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_two_negotiations_from_same_revision_publish_only_once(): void
    {
        $provider = $this->provider();
        $this->assertSame(['200', '409'], $this->paused(['negotiate', 'negotiate'], $provider, static fn () => null));
        $this->assertSame(1, $provider->fresh()->authorization_revision);
        $this->assertSame('2.2.1', $provider->fresh()->version);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_old_pull_cannot_commit_after_new_authorized_generation_is_negotiated(): void
    {
        $provider = $this->provider();
        $provider->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $this->assertSame(['409'], $this->paused(['ingest'], $provider, function () use ($provider) {
            $this->authorize($provider, false);
            $this->authorize($provider, true);
            $envelope = static fn ($data) => ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $data];
            Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/ocpi/versions') ? $envelope([['version' => '2.2.1', 'url' => 'https://partner.example/details']]) : $envelope(['version' => '2.2.1', 'endpoints' => [['identifier' => 'locations', 'role' => 'SENDER', 'url' => 'https://partner.example/locations']]])));
            app(PartnerClient::class)->negotiate($provider->fresh());
        }));
        $this->assertSame(3, $provider->fresh()->authorization_revision);
        $this->assertSame('2.2.1', $provider->fresh()->version);
        $this->assertDatabaseCount('ocpi_records', 0);
    }
}
