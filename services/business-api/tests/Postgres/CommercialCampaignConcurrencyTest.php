<?php

namespace Tests\Postgres;

use App\Services\Commercial\Campaigns\CampaignService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommercialCampaignConcurrencyTest extends TestCase
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
            $this->markTestSkipped('Requires isolated disposable PostgreSQL.');
        }
        parent::setUp();
    }

    private function race(string $operation, array $inputs): array
    {
        $dir = sys_get_temp_dir().'/campaign-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($inputs as $n => $input) {
                file_put_contents($dir.'/input-'.$n, json_encode($input, JSON_THROW_ON_ERROR));
                $pipes = [];
                $ready = $dir.'/ready-'.$n;
                $process = proc_open([PHP_BINARY, base_path('tests/Support/campaign-concurrency-worker.php'), $operation, $dir.'/input-'.$n, $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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

    public function test_concurrent_same_creation_key_reserves_one_campaign_and_preserves_cash(): void
    {
        $f = (require base_path('tests/Fixtures/commercial-campaigns.php'))();
        $cash = DB::table('commercial_subscription_ledger')->count();
        $input = ['vendor' => $f['vendor']->id, 'values' => $f['values']];
        $results = $this->race('create', [$input, $input]);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('commercial_campaigns', 1);
        $this->assertDatabaseCount('commercial_subscription_ledger', $cash);
    }

    public function test_distinct_concurrent_campaigns_cannot_overreserve_paid_package_units(): void
    {
        $f = (require base_path('tests/Fixtures/commercial-campaigns.php'))();
        $one = ['vendor' => $f['vendor']->id, 'values' => $f['values']];
        $two = $one;
        $two['values']['idempotency_key'] = 'other';
        $statuses = array_column($this->race('create', [$one, $two]), 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('commercial_campaigns', 1);
    }

    private function deliveryFixture(): array
    {
        $f = (require base_path('tests/Fixtures/commercial-campaigns.php'))();
        $f['values']['budget_units'] = 1;
        $s = app(CampaignService::class);
        $c = $s->create($f['vendor'], $f['values']);
        $s->action($c->id, $f['vendor'], 'submit', 'Synthetic review request');
        $s->action($c->id, $f['admin'], 'approve', 'Synthetic approved campaign');

        return ['campaign_id' => $c->id, 'event_id' => 'campaign-delivery', 'kind' => 'delivery', 'parent_event_id' => null, 'country' => 'AE', 'region' => null, 'placement' => 'discovery_banner', 'occurred_at' => now()->toDateTimeString()];
    }

    public function test_concurrent_verified_delivery_events_cannot_overspend_one_remaining_unit(): void
    {
        $v = $this->deliveryFixture();
        $two = $v;
        $two['event_id'] = 'another';
        $statuses = array_column($this->race('delivery', [['values' => $v], ['values' => $two]]), 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('commercial_campaign_deliveries', 1);
    }

    public function test_same_verified_event_is_idempotent_across_processes(): void
    {
        $v = $this->deliveryFixture();
        $results = $this->race('delivery', [['values' => $v], ['values' => $v]]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $duplicates = array_column($results, 'duplicate');
        sort($duplicates);
        $this->assertSame([false, true], $duplicates);
        $this->assertDatabaseCount('commercial_campaign_deliveries', 1);
    }
}
