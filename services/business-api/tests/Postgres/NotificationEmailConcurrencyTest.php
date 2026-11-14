<?php

namespace Tests\Postgres;

use App\Models\InboxNotification;
use App\Models\User;
use App\Services\Notifications\EmailNotices;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationEmailConcurrencyTest extends TestCase
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
        $dir = sys_get_temp_dir().'/notification-email-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $workers = [];
        try {
            foreach ([0, 1] as $i) {
                $pipes = [];
                $ready = $dir.'/ready-'.$i;
                $p = proc_open([PHP_BINARY, base_path('tests/Support/notification-email-worker.php'), $operation, $id, $dir.'/gate', $ready], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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

    public function test_independent_workers_prepare_and_claim_one_digest_once(): void
    {
        config(['notification_email.enabled' => true, 'app.url' => 'https://platform.example', 'mail.default' => 'array', 'mail.from.address' => 'notices@example.test']);
        $user = User::factory()->create();
        app(EmailNotices::class)->preference($user, true, 0);
        InboxNotification::create(['user_id' => $user->id, 'kind' => 'other.account_event', 'metadata' => []]);
        $prepared = $this->race('prepare');
        $this->assertSame(1, array_sum(array_column($prepared, 'result')));
        $this->assertDatabaseCount('notification_email_outbox', 1);
        $row = DB::table('notification_email_outbox')->first();
        $sent = $this->race('deliver', $row->id);
        $results = array_column($sent, 'result');
        sort($results);
        $this->assertSame(['handed_off', 'not_attempted'], $results);
        $this->assertSame(1, array_sum(array_column($sent, 'sends')));
        $this->assertSame('handed_off', DB::table('notification_email_outbox')->value('state'));
        $this->assertSame('not_attempted', app(EmailNotices::class)->deliver($row->id));
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }
}
