<?php

namespace Tests\Postgres;

use App\Models\User;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionConcurrencyTest extends TestCase
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

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create();
        $plan = app(SubscriptionService::class)->publish($admin, ['code' => 'customer-pro', 'name' => 'Customer Pro', 'audience' => 'customer', 'currency' => 'AED', 'minor_units' => 2, 'price_minor' => 1000, 'tax_bps' => 500, 'interval' => 'month', 'entitlements' => json_encode(['priority_support' => true])]);

        return [$customer, $plan];
    }

    private function race(string $operation, int $entity, int $actor, int $invoice = 0, bool $differentKeys = false): array
    {
        $table = match ($operation) {
            'dunning' => 'subscription_dunning_notices', 'enroll' => 'commercial_subscriptions', 'renew' => 'commercial_subscription_invoices', 'pay', 'gateway_capture' => 'commercial_subscription_receipts', 'credit' => 'subscription_credits'
        };
        DB::unprepared('CREATE FUNCTION subscription_test_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.5); RETURN NEW; END; $$');
        DB::unprepared('CREATE TRIGGER subscription_test_delay BEFORE INSERT ON '.$table.' FOR EACH ROW EXECUTE FUNCTION subscription_test_delay()');
        $directory = sys_get_temp_dir().'/subscription-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $gate = $directory.'/start';
        $workers = [];
        try {
            foreach ([0, 1] as $index) {
                $ready = $directory.'/ready-'.$index;
                $pipes = [];
                $key = $differentKeys ? 'race-'.$index : 'race-same';
                $process = proc_open([PHP_BINARY, base_path('tests/Support/subscription-concurrency-worker.php'), $operation, (string) $entity, (string) $actor, (string) $invoice, $key, $gate, $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            DB::unprepared('DROP TRIGGER IF EXISTS subscription_test_delay ON '.$table);
            DB::unprepared('DROP FUNCTION IF EXISTS subscription_test_delay()');
        }
    }

    public function test_racing_dunning_workers_record_one_notice_without_payment(): void
    {
        [$customer, $plan] = $this->fixture();
        $this->travelTo(now()->subDays(2));
        $s = app(SubscriptionService::class)->subscribe($customer, $plan->id, 'fixture');
        $this->travelBack();
        $this->assertSame(['200', '200'], $this->race('dunning', $s['invoices']->first()->id, $customer->id));
        $this->assertDatabaseCount('subscription_dunning_notices', 1);
        $this->assertDatabaseCount('commercial_subscription_receipts', 0);
        $this->assertDatabaseCount('subscription_gateway_checkouts', 0);
        $this->assertTrue(app(SubscriptionService::class)->summary($s['subscription']->id)['ledger_balanced']);
    }

    public function test_racing_enrollment_same_key_returns_one_subscription_and_invoice(): void
    {
        [$customer, $plan] = $this->fixture();
        $this->assertSame(['200', '200'], $this->race('enroll', $plan->id, $customer->id));
        $this->assertDatabaseCount('commercial_subscriptions', 1);
        $this->assertDatabaseCount('commercial_subscription_invoices', 1);
        $this->assertDatabaseCount('commercial_subscription_events', 1);
    }

    public function test_racing_distinct_enrollment_keys_cannot_create_two_active_subscriptions(): void
    {
        [$customer, $plan] = $this->fixture();
        $this->assertSame(['200', '409'], $this->race('enroll', $plan->id, $customer->id, 0, true));
        $this->assertDatabaseCount('commercial_subscriptions', 1);
        $this->assertDatabaseCount('commercial_subscription_invoices', 1);
    }

    public function test_racing_mock_payment_replays_post_one_receipt_and_balanced_journal(): void
    {
        [$customer, $plan] = $this->fixture();
        $s = app(SubscriptionService::class)->subscribe($customer, $plan->id, 'fixture');
        $id = $s['subscription']->id;
        $invoice = $s['invoices']->first()->id;
        $this->assertSame(['200', '200'], $this->race('pay', $id, $customer->id, $invoice));
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertSame(2, DB::table('commercial_subscription_ledger')->where('kind', 'payment')->count());
        $this->assertTrue(app(SubscriptionService::class)->summary($id)['ledger_balanced']);
        $this->assertSame(1050, (int) DB::table('commercial_subscription_receipts')->sum('amount_minor'));
    }

    public function test_racing_distinct_payment_keys_cannot_double_charge_invoice(): void
    {
        [$customer, $plan] = $this->fixture();
        $s = app(SubscriptionService::class)->subscribe($customer, $plan->id, 'fixture');
        $this->assertSame(['200', '409'], $this->race('pay', $s['subscription']->id, $customer->id, $s['invoices']->first()->id, true));
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
    }

    public function test_racing_due_renewals_issue_one_cycle_without_capturing_money(): void
    {
        [$customer, $plan] = $this->fixture();
        $this->travelTo(now()->subMonths(2));
        $service = app(SubscriptionService::class);
        $s = $service->subscribe($customer, $plan->id, 'fixture');
        $id = $s['subscription']->id;
        $service->mockPay($customer, $id, $s['invoices']->first()->id, 'succeeded', 'fixture-pay');
        $this->travelBack();
        $this->assertSame(['200', '200'], $this->race('renew', $id, $customer->id));
        $this->assertDatabaseCount('commercial_subscription_invoices', 2);
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertSame(1, DB::table('commercial_subscription_events')->where('kind', 'renewal_invoiced')->count());
        $this->assertFalse($service->summary($id)['entitlements_active']);
    }

    public function test_racing_gateway_captures_share_single_receipt_and_payment_journal(): void
    {
        config(['payments.provider' => 'mock']);
        [$customer, $plan] = $this->fixture();
        $s = app(SubscriptionService::class)->subscribe($customer, $plan->id, 'fixture');
        $gateway = app(SubscriptionGateway::class);
        $c = $gateway->checkout($customer, $s['subscription']->id, $s['invoices']->first()->id, 'checkout');
        $gateway->operate($customer, $c->id, 'authorize', 'authorize', 'mock_success');
        $this->assertSame(['200', '200'], $this->race('gateway_capture', $c->id, $customer->id, 0, true));
        $this->assertDatabaseCount('commercial_subscription_receipts', 1);
        $this->assertSame(1, DB::table('subscription_gateway_operations')->where('kind', 'capture')->count());
        $this->assertSame(2, DB::table('commercial_subscription_ledger')->where('kind', 'payment')->count());
        $this->assertTrue(app(SubscriptionService::class)->summary($s['subscription']->id)['ledger_balanced']);
    }

    public function test_racing_subscription_credit_retries_post_one_immutable_refund(): void
    {
        [$customer, $plan] = $this->fixture();
        $admin = User::where('role', 'admin')->firstOrFail();
        $service = app(SubscriptionService::class);
        $s = $service->subscribe($customer, $plan->id, 'fixture');
        $invoice = $s['invoices']->first()->id;
        $service->mockPay($customer, $s['subscription']->id, $invoice, 'succeeded', 'pay');
        $this->assertSame(['200', '200'], $this->race('credit', $s['subscription']->id, $admin->id, $invoice));
        $this->assertDatabaseCount('subscription_credits', 1);
        $this->assertSame(1050, (int) DB::table('subscription_credits')->sum('amount_minor'));
        $summary = $service->summary($s['subscription']->id);
        $this->assertTrue($summary['ledger_balanced']);
        $this->assertFalse($summary['entitlements_active']);
    }

    public function test_racing_distinct_subscription_credit_keys_cannot_exceed_capture(): void
    {
        [$customer, $plan] = $this->fixture();
        $admin = User::where('role', 'admin')->firstOrFail();
        $service = app(SubscriptionService::class);
        $s = $service->subscribe($customer, $plan->id, 'fixture');
        $invoice = $s['invoices']->first()->id;
        $service->mockPay($customer, $s['subscription']->id, $invoice, 'succeeded', 'pay');
        $this->assertSame(['200', '409'], $this->race('credit', $s['subscription']->id, $admin->id, $invoice, true));
        $this->assertDatabaseCount('subscription_credits', 1);
        $this->assertSame(0, $service->summary($s['subscription']->id)['net_collected_minor']);
    }
}
