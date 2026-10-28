<?php

namespace Tests\Postgres;

use App\Models\User;
use App\Services\Commercial\SubscriptionCollections;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecurringContract;
use Tests\TestCase;

class SubscriptionRecurringConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposableDatabase; }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires a disposable PostgreSQL testing database.');
        }
        parent::setUp();
    }

    private array $providerCalls = [];

    private function fixture(): array
    {
        $user = User::factory()->create();
        $method = RecurringContract::mandate($user);
        $service = app(SubscriptionService::class);
        $plan = $service->publish(User::factory()->create(['role' => 'admin']), ['code' => 'recurring-pro', 'name' => 'Recurring Pro', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => json_encode(['premium_reports' => true])]);
        $s = $service->subscribe($user, $plan->id, 'enroll');
        app(SubscriptionCollections::class)->policy($user, $s['subscription']->id, true, $method, 0);

        return [$user, $s['subscription']->id, $s['invoices']->first()->id];
    }

    private function race(string $operation, int $entity, int $actor): array
    {
        $directory = sys_get_temp_dir().'/subscription-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $gate = $directory.'/start';
        $workers = [];
        try {
            foreach ([0, 1] as $index) {
                $ready = $directory.'/ready-'.$index;
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/recurring-concurrency-worker.php'), ($operation === 'revoke' && $index === 1 ? 'revoke' : 'collect'), (string) $entity, (string) $actor, $gate, $ready, $directory.'/calls', 'normal'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            $this->providerCalls = is_file($directory.'/calls') ? file($directory.'/calls', FILE_IGNORE_NEW_LINES) : [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                } proc_close($process);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);

        }
    }

    public function test_independent_workers_collect_once_with_one_provider_authorization(): void
    {
        [$user,$s,$i] = $this->fixture();
        $this->assertSame(['200', '200'], $this->race('collect', $i, $user->id));
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 1);
        $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'collected', 'attempts' => 1]);
        $this->assertCount(1, array_filter($this->providerCalls, fn ($v) => json_decode($v, true) === ['POST', '/v1/payment_intents']));
        $this->assertTrue(app(SubscriptionService::class)->summary($s)['ledger_balanced']);
    }

    public function test_revocation_racing_collection_cannot_leave_future_consent_enabled(): void
    {
        [$user,$s,$i] = $this->fixture();
        $this->assertSame(['200', '200'], $this->race('revoke', $i, $user->id));
        $this->assertDatabaseHas('subscription_collection_policies', ['subscription_id' => $s, 'enabled' => false]);
        $this->assertDatabaseHas('payment_mandates', ['user_id' => $user->id, 'status' => 'revoked']);
        $this->assertLessThanOrEqual(1, DB::table('commercial_subscription_receipts')->count());
        $this->assertTrue(app(SubscriptionService::class)->summary($s)['ledger_balanced']);
    }

    public function test_process_exit_after_provider_acceptance_recovers_by_get_without_replacement_charge(): void
    {
        [$user,$s,$i] = $this->fixture();
        $dir = sys_get_temp_dir().'/recurring-crash-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        touch($dir.'/gate');
        try {
            $run = function (string $mode) use ($dir, $user, $i): int {
                $pipes = [];
                $p = proc_open([PHP_BINARY, base_path('tests/Support/recurring-concurrency-worker.php'), 'collect', (string) $i, (string) $user->id, $dir.'/gate', $dir.'/ready', $dir.'/calls', $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($p);
                stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($p);
                $this->assertSame('', $error);

                return $status;
            };
            $this->assertSame(91, $run('crash'));
            $this->assertDatabaseHas('payment_recovery_intents', ['scope' => 'subscription', 'status' => 'executing']);
            $this->assertDatabaseCount('commercial_subscription_receipts', 0);
            // Advance only disposable test leases, simulating worker death and lease expiry.
            DB::table('payment_recovery_intents')->update(['lease_until' => now()->subMinute()]);
            DB::table('subscription_collections')->update(['lease_until' => now()->subMinute()]);
            $this->assertSame(0, $run('normal'));
            $this->assertDatabaseHas('subscription_collections', ['invoice_id' => $i, 'state' => 'collected', 'attempts' => 1]);
            $this->assertDatabaseCount('commercial_subscription_receipts', 1);
            $calls = file($dir.'/calls', FILE_IGNORE_NEW_LINES);
            $this->assertCount(1, array_filter($calls, fn ($v) => json_decode($v, true) === ['POST', '/v1/payment_intents']));
            $this->assertCount(1, array_filter($calls, fn ($v) => json_decode($v, true) === ['GET', '/v1/payment_intents/search']));
        } finally {
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
