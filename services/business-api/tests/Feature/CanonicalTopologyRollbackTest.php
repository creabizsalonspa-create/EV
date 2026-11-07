<?php

namespace Tests\Feature;

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CanonicalTopologyRollbackTest extends TestCase
{
    private bool $disposable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $driver = DB::connection()->getDriverName();
        $name = DB::connection()->getDatabaseName();
        $this->assertTrue(($driver === 'sqlite' && $name === ':memory:') || ($driver === 'pgsql' && str_ends_with($name, '_review')), 'Only an explicitly disposable database may be rebuilt.');
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

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Rollback fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Rollback', 'country' => 'AE', 'trade_license_number' => 'TEST', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Rollback', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'TEST', 'manufacturer' => 'Synthetic', 'model' => 'TEST', 'source' => 'simulator', 'ocpp_protocol' => '1.6']);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => 'ev-test-rollback', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);

        return [$station, $charger, $binding];
    }

    public function test_disposable_forward_downgrade_forward_preserves_legacy_binding_and_restores_indexes(): void
    {
        [$station,$charger,$binding] = $this->fixture();
        $migration = require database_path('migrations/2026_10_29_000001_create_canonical_charging_topology.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('csms_bindings', 'vendor_charger_id'));
        $this->assertFalse(Schema::hasTable('charging_evses'));
        $this->assertDatabaseHas('csms_bindings', ['id' => $binding, 'identifier' => 'ev-test-rollback']);
        $this->assertTrue(Schema::hasIndex('csms_bindings', 'csms_bindings_station_id_unique'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('csms_bindings', 'vendor_charger_id'));
        $this->assertTrue(Schema::hasIndex('csms_bindings', 'csms_bindings_vendor_charger_id_unique'));
        $this->assertTrue(Schema::hasIndex('reservations', 'reservations_active_evse_slot'));
        $this->assertDatabaseHas('csms_bindings', ['id' => $binding, 'vendor_charger_id' => null]);
    }

    public static function histories(): array
    {
        return [['evse'], ['binding'], ['mapping'], ['multiple']];
    }

    #[DataProvider('histories')]
    public function test_configured_resources_refuse_downgrade_before_any_schema_change(string $kind): void
    {
        [$station,$charger,$binding] = $this->fixture();
        if ($kind === 'binding') {
            DB::table('csms_bindings')->where('id', $binding)->update(['vendor_charger_id' => $charger->id]);
        }
        if ($kind === 'multiple') {
            DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-other', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (in_array($kind, ['evse', 'mapping'], true)) {
            $evse = Evse::create(['charger_id' => $charger->id, 'number' => 1, 'mode' => 'application', 'source_evse_number' => 0]);
            if ($kind === 'mapping') {
                $connector = VendorConnector::create(['charger_id' => $charger->id, 'connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 50]);
                ConnectorMapping::create(['station_id' => $station->id, 'evse_id' => $evse->id, 'connector_id' => $connector->id]);
            }
        }
        $migration = require database_path('migrations/2026_10_29_000001_create_canonical_charging_topology.php');
        try {
            $migration->down();
            $this->fail('Configured history must block rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('csms_bindings', 'vendor_charger_id'));
        $this->assertTrue(Schema::hasTable('charging_evses'));
        $this->assertTrue(Schema::hasIndex('reservations', 'reservations_active_evse_slot'));
    }
}
