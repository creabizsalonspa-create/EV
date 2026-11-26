<?php

namespace Tests\Postgres;

use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Retail\PartnerRegistry;
use App\Services\Retail\PartnerRewardGovernance;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetailPartnerConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private migrateDisposableDatabase; }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
        $this->migrateDisposableDatabase();
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Requires disposable PostgreSQL testing database.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/_(review|test|testing)$/', DB::connection()->getDatabaseName());
    }

    private function terms(): array
    {
        return ['reference' => 'RETAIL-RACE', 'legal_name' => 'Test Retail', 'display_name' => 'Test', 'display_name_ar' => 'اختبار', 'country' => 'AE', 'category' => 'retail', 'website' => null, 'terms' => 'Reviewed synthetic terms without monetary authority.', 'effective_from' => now()->subDay()->toIso8601String(), 'expires_at' => now()->addMonth()->toIso8601String(), 'reason' => 'Synthetic concurrency review', 'idempotency_key' => (string) Str::uuid()];
    }

    private function race(array $operations): array
    {
        $dir = sys_get_temp_dir().'/retail-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($operations as $i => [$actor, $partner, $action, $payload]) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $p = proc_open([PHP_BINARY, base_path('tests/Support/retail-partner-worker.php'), (string) $actor, (string) $partner, $action, json_encode($payload, JSON_THROW_ON_ERROR), $dir.'/go', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($p);
                $workers[] = [$p, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (count(array_filter($workers, fn ($w) => is_file($w[2]))) < count($workers) && microtime(true) < $deadline) {
                usleep(5000);
            }
            foreach ($workers as $w) {
                $this->assertFileExists($w[2]);
            }
            touch($dir.'/go');
            $statuses = [];
            foreach ($workers as [$p, $pipes]) {
                $statuses[] = trim(stream_get_contents($pipes[1]));
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $errors);
            }
            $workers = [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($workers as [$p, $pipes]) {
                proc_terminate($p);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                } proc_close($p);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
        }
    }

    public function test_same_key_creation_race_has_one_history_and_no_duplicate_partner(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $v = $this->terms();
        $this->assertSame(['200', '200'], $this->race([[$a->id, 0, 'create', $v], [$a->id, 0, 'create', $v]]));
        $this->assertSame(1, DB::table('retail_partners')->count());
        $this->assertSame(1, DB::table('retail_partner_events')->count());
    }

    public function test_different_admin_reference_race_returns_conflict_not_database_error(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $b = User::factory()->create(['role' => 'admin']);
        $this->assertSame(['200', '409'], $this->race([[$a->id, 0, 'create', $this->terms()], [$b->id, 0, 'create', $this->terms()]]));
        $this->assertSame(1, DB::table('retail_partners')->count());
    }

    public function test_competing_reviews_cannot_both_consume_the_same_revision(): void
    {
        $a = User::factory()->create(['role' => 'admin']);
        $b = User::factory()->create(['role' => 'admin']);
        $c = User::factory()->create(['role' => 'admin']);
        $p = app(PartnerRegistry::class)->mutate($a, null, 'create', $this->terms());
        $decision = ['reason' => 'Independent synthetic review', 'expected_revision' => 1, 'idempotency_key' => (string) Str::uuid()];
        $this->assertSame(['200', '409'], $this->race([[$b->id, $p['id'], 'approve', $decision], [$c->id, $p['id'], 'terminate', $decision]]));
        $this->assertSame(2, DB::table('retail_partner_events')->count());
    }

    public function test_approval_and_rejection_cannot_both_decide_the_same_draft(): void
    {
        $author = User::factory()->create(['role' => 'admin']);
        $approver = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $partner = app(PartnerRegistry::class)->mutate($author, null, 'create', $this->terms());
        $decision = ['reason' => 'Independent synthetic review', 'expected_revision' => 1, 'idempotency_key' => (string) Str::uuid()];

        $this->assertSame(['200', '409'], $this->race([
            [$approver->id, $partner['id'], 'approve', $decision],
            [$reviewer->id, $partner['id'], 'reject', $decision],
        ]));
        $this->assertDatabaseCount('retail_partner_events', 2);
        $final = app(PartnerRegistry::class)->read($partner['id']);
        $this->assertSame(2, $final['revision']);
        $this->assertContains($final['status'], ['active', 'rejected']);
    }

    public function test_competing_reward_decisions_record_one_review_event_without_fulfillment(): void
    {
        config(['loyalty.enabled' => true]);
        $maker = User::factory()->create(['role' => 'admin']);
        $approver = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $registry = app(PartnerRegistry::class);
        $partner = $registry->mutate($maker, null, 'create', $this->terms())['id'];
        $decision = ['reason' => 'Independent synthetic review', 'expected_revision' => 1, 'idempotency_key' => (string) Str::uuid()];
        $registry->mutate($approver, $partner, 'approve', $decision);
        $reward = app(LoyaltyService::class)->publish('loyalty_rewards', $maker, [
            'name' => 'Synthetic partner reward', 'name_ar' => 'مكافأة شريك تجريبية',
            'description' => 'Review only', 'description_ar' => 'للمراجعة فقط',
            'currency' => 'AED', 'points_cost' => 1, 'stock' => 2, 'per_user_limit' => 1,
            'starts_at' => now()->utc()->toDateTimeString(), 'ends_at' => now()->utc()->addDay()->toDateTimeString(),
        ], 'retail-review-race');
        $proposal = app(PartnerRewardGovernance::class)->record($maker, $partner, null, 'propose', [
            'reason' => 'Synthetic reward review proposal', 'expected_partner_revision' => 2,
            'reward_id' => $reward->id, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $decision['review'] = $proposal['id'];

        $this->assertSame(['200', '409'], $this->race([
            [$approver->id, $partner, 'reward:approve', $decision],
            [$reviewer->id, $partner, 'reward:reject', $decision],
        ]));
        $this->assertDatabaseCount('partner_reward_review_events', 2);
        $this->assertDatabaseCount('loyalty_redemptions', 0);
        $this->assertDatabaseCount('loyalty_ledger', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }
}
