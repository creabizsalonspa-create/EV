<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteConcurrencyConfigurationTest extends TestCase
{
    public function test_local_sqlite_busy_wait_is_bounded_and_applied_to_the_connection(): void
    {
        $configured = config('database.connections.sqlite.busy_timeout');

        if ($configured === null) {
            $this->markTestSkipped('SQLite contention wait not configured for this environment.');
        }

        $this->assertIsInt($configured);
        $this->assertGreaterThan(0, $configured);
        $this->assertLessThanOrEqual(10000, $configured);

        $observed = DB::connection('sqlite')->selectOne('PRAGMA busy_timeout');
        $this->assertNotNull($observed);
        $this->assertSame($configured, (int) $observed->timeout);
    }
}
