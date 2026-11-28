<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseClockTest extends TestCase
{
    public function test_postgres_interprets_laravel_utc_datetime_bindings_as_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL.');
        }

        $instant = CarbonImmutable::parse('2026-11-28T05:30:00Z');
        $row = DB::selectOne('SELECT current_setting(\'TimeZone\') AS timezone,
            EXTRACT(EPOCH FROM CAST(? AS timestamptz)) AS stored_epoch', [$instant]);

        $this->assertSame('UTC', $row->timezone);
        $this->assertSame($instant->timestamp, (int) $row->stored_epoch);
    }
}
