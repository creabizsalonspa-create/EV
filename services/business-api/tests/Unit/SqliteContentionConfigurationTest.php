<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteContentionConfigurationTest extends TestCase
{
    public function test_sqlite_busy_timeout_is_bounded_and_applied_to_the_connection(): void
    {
        $configured = config('database.connections.sqlite.busy_timeout');

        $this->assertIsInt($configured);
        $this->assertGreaterThan(0, $configured);
        $this->assertLessThanOrEqual(20000, $configured);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $pragma = DB::selectOne('PRAGMA busy_timeout');
        $this->assertNotNull($pragma);
        $this->assertSame($configured, (int) $pragma->timeout);
    }
}
