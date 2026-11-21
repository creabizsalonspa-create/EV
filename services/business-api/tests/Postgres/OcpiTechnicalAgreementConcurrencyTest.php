<?php

namespace Tests\Postgres;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\TechnicalAgreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OcpiTechnicalAgreementConcurrencyTest extends TestCase
{
    private User $admin;

    private OcpiProvider $provider;

    private array $configuration;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Disposable PostgreSQL process test only.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_review', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->configuration = ['ocpi.connections.agreement-race' => ['origin' => 'https://peer.example', 'authorization_token' => base64_encode('SYNTHETIC-ONLY-BOOTSTRAP'),
            'managed_credentials' => true, 'technical_agreement_required' => true, 'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'CPO'],
            'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic race source']]]]];
        config($this->configuration);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $this->provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic agreement race', 'connection_key' => 'agreement-race', 'authorized' => false]);
    }

    private function draft(): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => 0, 'reference' => 'SYNTHETIC-PG-REVIEW',
            'evidence_sha256' => hash('sha256', 'synthetic no real partner'), 'terms_en' => 'Synthetic technical scope only; no live approval.', 'terms_ar' => 'نطاق تقني اصطناعي لاختبار التزامن فقط دون شريك فعلي.',
            'allowed_modules' => ['locations'], 'valid_from' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z'), 'valid_until' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    private function decision(string $action): array
    {
        return ['request_key' => (string) Str::uuid(), 'expected_revision' => 1, 'action' => $action, 'reason' => 'Synthetic explicit human review for PostgreSQL race verification only.'];
    }

    private function race(array $operations, ?callable $during = null): array
    {
        $directory = sys_get_temp_dir().'/ocpi-agreement-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/input.json', json_encode(['configuration' => $this->configuration,
            'admin_id' => $this->admin->id, 'provider_id' => $this->provider->id, 'operations' => $operations], JSON_THROW_ON_ERROR));
        chmod($directory.'/input.json', 0600);
        $workers = [];
        try {
            foreach ($operations as $index => $_) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ocpi-technical-agreement-worker.php'), $directory, (string) $index], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes, $directory.'/ready-'.$index];
            }
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline && count(array_filter($workers, fn ($w) => is_file($w[2]))) !== count($workers)) {
                usleep(5000);
            }
            foreach ($workers as $worker) {
                $this->assertFileExists($worker[2]);
            }
            if ($during) {
                $during();
            }
            touch($directory.'/go');
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $body = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $results[] = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            }
            $workers = [];

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

    public function test_parallel_distinct_drafts_cannot_both_claim_the_same_revision(): void
    {
        $results = $this->race([['kind' => 'propose', 'body' => $this->draft()], ['kind' => 'propose', 'body' => $this->draft()]]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, DB::table('ocpi_technical_agreements')->count());
        $this->assertSame(1, DB::table('ocpi_technical_agreement_heads')->value('revision'));
    }

    public function test_duplicate_lost_ack_workers_return_one_immutable_version(): void
    {
        $draft = $this->draft();
        $results = $this->race([['kind' => 'propose', 'body' => $draft], ['kind' => 'propose', 'body' => $draft]]);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, DB::table('ocpi_technical_agreements')->count());
    }

    public function test_parallel_approval_and_rejection_can_record_only_one_review(): void
    {
        $id = app(TechnicalAgreement::class)->propose($this->provider, $this->admin, $this->draft());
        $results = $this->race([['kind' => 'decide', 'agreement_id' => $id, 'body' => $this->decision('approved')], ['kind' => 'decide', 'agreement_id' => $id, 'body' => $this->decision('rejected')]]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, DB::table('ocpi_technical_agreement_events')->count());
        $this->assertFalse($this->provider->fresh()->authorized);
    }

    public function test_revocation_racing_authorization_always_leaves_provider_withdrawn(): void
    {
        $service = app(TechnicalAgreement::class);
        $id = $service->propose($this->provider, $this->admin, $this->draft());
        $service->decide($this->provider, $this->admin, $id, $this->decision('approved'));
        $results = $this->race([['kind' => 'authorize'], ['kind' => 'decide', 'agreement_id' => $id, 'body' => $this->decision('revoked')]]);
        $this->assertContains($results[0]['status'], [200, 422]);
        $this->assertSame(200, $results[1]['status']);
        $this->assertFalse($this->provider->fresh()->authorized);
        $this->assertSame([], $service->allowed($this->provider->fresh()));
        $this->assertSame(0, DB::table('finance_invoices')->count());
        $this->assertSame(0, DB::table('charger_commands')->count());
    }

    public function test_role_revoked_after_worker_snapshot_cannot_commit_a_proposal(): void
    {
        $results = $this->race([['kind' => 'propose', 'body' => $this->draft()]], function () {
            DB::table('users')->where('id', $this->admin->id)->update(['role' => 'customer']);
        });
        $this->assertSame(403, $results[0]['status']);
        $this->assertSame(0, DB::table('ocpi_technical_agreements')->count());
    }
}
