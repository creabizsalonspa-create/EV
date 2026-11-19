<?php

namespace Tests\Postgres;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OcpiReceiptPrecisionTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql' || getenv('APP_ENV') !== 'testing') {
            $this->markTestSkipped('Explicit disposable PostgreSQL required.');
        }
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(str_ends_with(DB::connection()->getDatabaseName(), '_review'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    private function record(string $received): OcpiRecord
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic precision source', 'connection_key' => 'precision-'.bin2hex(random_bytes(6)), 'authorized' => false]);

        return OcpiRecord::create(['ocpi_provider_id' => $provider->id, 'module' => 'cdrs', 'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'private-precision-record', 'payload' => ['fixture_only' => true], 'normalized' => ['fixture_only' => true], 'content_hash' => str_repeat('a', 64), 'source_updated_at' => '2026-11-19T05:00:00.123456Z', 'received_at' => $received]);
    }

    public function test_precision_upgrade_preserves_existing_immutable_cdr_evidence_without_backfill(): void
    {
        // Reproduce the previously deployed whole-second column shape.
        DB::statement('ALTER TABLE ocpi_records ALTER COLUMN received_at TYPE timestamp(0) without time zone');
        $record = $this->record('2026-11-19T05:10:00Z');
        $before = (array) DB::table('ocpi_records')->where('id', $record->id)->first();
        $migration = require database_path('migrations/2026_12_04_000001_preserve_ocpi_receipt_microseconds.php');
        $migration->up();
        $this->assertSame($before, (array) DB::table('ocpi_records')->where('id', $record->id)->first());
        $this->assertSame('2026-11-19T05:10:00.000000Z', $record->fresh()->received_at->toISOString());
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_non_disposable_rollback_refuses_to_round_retained_receipt_evidence(): void
    {
        $record = $this->record('2026-11-19T05:10:00.987654Z');
        $migration = require database_path('migrations/2026_12_04_000001_preserve_ocpi_receipt_microseconds.php');
        $environment = app()->environment();
        // The connection remains the asserted disposable review database;
        // only exercise the deployment guard rather than its test exemption.
        app()['env'] = 'local';
        try {
            try {
                $migration->down();
                $this->fail('Retained microseconds must not be silently rounded.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Preserve OCPI receipt instants', $error->getMessage());
            }
            $stored = $record->fresh()->received_at;
            $this->assertTrue($stored->equalTo(CarbonImmutable::parse('2026-11-19T05:10:00.987654Z')));
            $this->assertDatabaseCount('ocpi_records', 1);
        } finally {
            app()['env'] = $environment;
        }
    }
}
