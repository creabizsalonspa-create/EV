<?php

namespace Tests\Postgres;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivacyRequestConcurrencyTest extends TestCase
{
    use DatabaseMigrations{runDatabaseMigrations as private migrateDisposable; }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Disposable PostgreSQL only.');
        }parent::setUp();
    }

    public function runDatabaseMigrations(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_review', DB::connection()->getDatabaseName());
        $this->migrateDisposable();
    }

    private function race(array $specs): array
    {
        $dir = sys_get_temp_dir().'/privacy-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ($specs as $i => $spec) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $body = base64_encode(json_encode($spec['body'], JSON_THROW_ON_ERROR));
                $p = proc_open([PHP_BINARY, base_path('tests/Support/connector-reservation-worker.php'), $body, (string) $spec['actor'], $dir.'/gate', $ready, $spec['operation']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
                $results[] = trim(stream_get_contents($pipes[1]));
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $err);
            }$workers = [];
            sort($results);

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

    public function test_same_identity_simultaneously_records_one_private_request_and_audit(): void
    {
        $user = User::factory()->create();
        $body = ['kind' => 'access', 'details' => 'Please review my retained personal data.', 'idempotency_key' => (string) Str::uuid()];
        $spec = ['operation' => 'privacy_submit', 'actor' => $user->id, 'body' => $body];
        $this->assertSame(['200', '201'], $this->race([$spec, $spec]));
        $this->assertDatabaseCount('account_privacy_requests', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.requested.access')->count());
    }

    public function test_different_payloads_cannot_share_one_identity(): void
    {
        $user = User::factory()->create();
        $body = ['kind' => 'access', 'details' => 'Please review my retained personal data.', 'idempotency_key' => (string) Str::uuid()];
        $this->assertSame(['201', '409'], $this->race([
            ['operation' => 'privacy_submit', 'actor' => $user->id, 'body' => $body],
            ['operation' => 'privacy_submit', 'actor' => $user->id, 'body' => array_replace($body, ['details' => 'Different private request explanation.'])],
        ]));
        $this->assertDatabaseCount('account_privacy_requests', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.requested.access')->count());
    }

    public function test_independent_reviewer_racing_with_withdrawal_cannot_resurrect_a_request(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $case = AccountPrivacyRequest::create([
            'user_id' => $owner->id, 'kind' => 'access', 'status' => 'pending',
            'request_details_encrypted' => Crypt::encryptString('Please review my retained personal data.'),
        ]);
        $statuses = $this->race([
            ['operation' => 'privacy_withdraw', 'actor' => $owner->id, 'body' => ['case_id' => $case->id]],
            ['operation' => 'privacy_review', 'actor' => $admin->id, 'body' => ['case_id' => $case->id, 'expected_status' => 'pending', 'status' => 'reviewing', 'review_note' => 'Independent documented request review.']],
        ]);
        $this->assertContains($statuses, [['200', '200'], ['200', '409']]);
        $this->assertSame('withdrawn', $case->fresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.request.withdrawn')->count());
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
