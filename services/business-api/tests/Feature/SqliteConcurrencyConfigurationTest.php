<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteConcurrencyConfigurationTest extends TestCase
{
    public function test_local_sqlite_busy_wait_is_bounded_and_applied_to_the_connection(): void
    {
        $configured = config('database.connections.sqlite.busy_timeout');

        $this->assertIsInt($configured);
        $this->assertGreaterThanOrEqual(1000, $configured);
        $this->assertLessThanOrEqual(10000, $configured);

        $observed = DB::connection('sqlite')->selectOne('PRAGMA busy_timeout');
        $this->assertNotNull($observed);
        $this->assertSame($configured, (int) $observed->timeout);
    }
}
