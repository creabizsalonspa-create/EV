<?php

namespace Tests\Postgres;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VendorOpsCreationConcurrencyTest extends TestCase
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
        $dir = sys_get_temp_dir().'/vendor-create-'.bin2hex(random_bytes(8));
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

    public static function payloads(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('payloads')]
    public function test_concurrent_creations_share_one_receipt_and_reject_changed_payload(bool $changed): void
    {
        $user = User::factory()->create();
        $body = ['legal_name' => 'Synthetic Company', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC-PRIVATE', 'license_expires_on' => now()->addYear()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $other = $changed ? array_replace($body, ['legal_name' => 'Different Company']) : $body;
        $statuses = $this->race([['operation' => 'vendor_company_create', 'actor' => $user->id, 'body' => $body], ['operation' => 'vendor_company_create', 'actor' => $user->id, 'body' => $other]]);
        $this->assertSame($changed ? ['201', '409'] : ['201', '201'], $statuses);
        $this->assertDatabaseCount('vendor_companies', 1);
        $this->assertDatabaseCount('vendor_creation_receipts', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'vendor_ops.company.created')->count());
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_equal_keys_for_different_accounts_never_return_another_tenants_company(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $body = ['legal_name' => 'Synthetic Company', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $this->assertSame(['201', '201'], $this->race([['operation' => 'vendor_company_create', 'actor' => $a->id, 'body' => $body], ['operation' => 'vendor_company_create', 'actor' => $b->id, 'body' => $body]]));
        $this->assertDatabaseCount('vendor_creation_receipts', 2);
        $this->assertSame(1, VendorCompany::where('owner_id', $a->id)->count());
        $this->assertSame(1, VendorCompany::where('owner_id', $b->id)->count());
    }

    public function test_revocation_racing_versioned_consent_cannot_leave_membership_active(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create();
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic Company', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $m = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'permissions' => ['inventory.read']]);
        $body = ['membership_id' => $m->id, 'company_id' => $company->id, 'expected_version' => 1];
        $statuses = $this->race([['operation' => 'vendor_staff_accept', 'actor' => $staff->id, 'body' => $body], ['operation' => 'vendor_staff_revoke', 'actor' => $owner->id, 'body' => $body]]);
        $this->assertContains($statuses, [['200', '204'], ['204', '409']]);
        $this->assertSame('revoked', $m->fresh()->status);
        $this->assertSame(2, $m->fresh()->invitation_version);
    }
}
