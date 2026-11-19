<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\AdminTransactionAuthority;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** No RefreshDatabase: an outer test transaction must not hide autocommit misuse. */
class AdminTransactionBoundaryTest extends TestCase
{
    public function test_privileged_guard_refuses_autocommit_before_any_account_lookup(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $snapshot = new User;
        $snapshot->setRawAttributes(['id' => 123, 'role' => 'admin', 'account_status' => 'active', 'auth_epoch' => 0, 'password' => 'synthetic-snapshot-hash'], true);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        try {
            AdminTransactionAuthority::requireCurrent($snapshot);
            $this->fail('An autocommit read cannot retain a privileged account row lock.');
        } catch (LogicException $error) {
            $this->assertSame('Administrator authority requires an active transaction.', $error->getMessage());
            $this->assertSame(0, $queries);
        }
    }
}
