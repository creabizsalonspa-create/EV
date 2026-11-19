<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Migrations may be destructively rehearsed only on disposable test databases. */
class ChargingSourceMigrationRollbackTest extends TestCase
{
    private bool $disposable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $driver = DB::connection()->getDriverName();
        $name = DB::connection()->getDatabaseName();
        $this->assertTrue(
            ($driver === 'sqlite' && $name === ':memory:') ||
            ($driver === 'pgsql' && str_ends_with($name, '_review')),
            'Never rebuild or downgrade a shared application database.'
        );
        $this->disposable = true;
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->disposable) {
                $this->artisan('migrate:fresh', ['--force' => true]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_empty_source_provenance_migrations_downgrade_and_restore_index_order(): void
    {
        $names = [
            '2026_11_16_000001_pin_charging_source_station_id.php',
            '2026_11_16_000002_pin_source_transaction_database_row.php',
            '2026_11_16_000003_unique_pinned_source_transaction_row.php',
            '2026_11_16_000004_track_source_meter_cursor_time.php',
            '2026_11_16_000005_index_status_source_order.php',
        ];
        $this->assertDatabaseCount('csms_session_links', 0);
        $this->assertDatabaseCount('charging_status_observations', 0);
        $this->assertTrue(Schema::hasColumns('csms_session_links', [
            'source_station_id', 'source_transaction_database_id', 'source_meter_cursor_at',
        ]));
        $this->assertTrue(Schema::hasIndex('csms_session_links', 'csms_link_binding_source_tx_unique'));
        $this->assertTrue(Schema::hasIndex('charging_status_observations', 'charging_observation_event_freshness'));

        foreach (array_reverse($names) as $filename) {
            (require database_path('migrations/'.$filename))->down();
        }
        $this->assertFalse(Schema::hasColumn('csms_session_links', 'source_station_id'));
        $this->assertFalse(Schema::hasColumn('csms_session_links', 'source_transaction_database_id'));
        $this->assertFalse(Schema::hasColumn('csms_session_links', 'source_meter_cursor_at'));
        $this->assertFalse(Schema::hasIndex('csms_session_links', 'csms_link_binding_source_tx_unique'));
        $this->assertFalse(Schema::hasIndex('charging_status_observations', 'charging_observation_event_freshness'));

        foreach ($names as $filename) {
            (require database_path('migrations/'.$filename))->up();
        }
        $this->assertTrue(Schema::hasColumns('csms_session_links', [
            'source_station_id', 'source_transaction_database_id', 'source_meter_cursor_at',
        ]));
        $this->assertTrue(Schema::hasIndex('csms_session_links', 'csms_link_binding_source_tx_unique'));
        $this->assertTrue(Schema::hasIndex('charging_status_observations', 'charging_observation_event_freshness'));
        $this->assertDatabaseCount('csms_session_links', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
