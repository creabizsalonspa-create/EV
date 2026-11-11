<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class OperatorRoleRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Fault injection needs a real outermost transaction. Fresh schema is
        // confined to this application's disposable in-memory connection;
        // no persistent application database or rollback history is touched.
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    private function account(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->createToken('Synthetic pre-promotion token');
        DB::table('sessions')->insert(['id' => 'synthetic-role-retry-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        return $user;
    }

    public function test_confirmed_rollback_retry_keeps_one_epoch_and_one_operator_audit(): void
    {
        $user = $this->account();
        $attempts = 0;
        DB::listen(function (QueryExecuted $query) use (&$attempts): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "operator_role_changes"') && ++$attempts === 1) {
                // Inject after the real audit INSERT: role, token/session
                // revocation and audit must all roll back before retrying.
                throw new PDOException('database is locked');
            }
        });
        $this->assertSame(0, Artisan::call('platform:role', ['email' => $user->email, 'role' => 'admin']));
        $this->assertSame(2, $attempts);
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertSame(1, $user->fresh()->auth_epoch);
        $this->assertDatabaseCount('operator_role_changes', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_exhausted_rollback_retries_never_leave_partial_privilege_or_revoke_credentials(): void
    {
        $user = $this->account();
        $attempts = 0;
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$attempts, &$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert into "operator_role_changes"')) {
                $attempts++;
                throw new PDOException('database is locked');
            }
        });
        try {
            Artisan::call('platform:role', ['email' => $user->email, 'role' => 'admin']);
            $this->fail('An unresolved database conflict must remain a failed command.');
        } catch (PDOException $error) {
            $this->assertSame('database is locked', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame(3, $attempts);
        $this->assertSame('customer', $user->fresh()->role);
        $this->assertSame(0, $user->fresh()->auth_epoch);
        $this->assertDatabaseCount('operator_role_changes', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('sessions', 1);
    }
}
