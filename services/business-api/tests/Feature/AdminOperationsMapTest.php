<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsMapController;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminOperationsMapTest extends TestCase
{
    use RefreshDatabase;

    private function station(array $values = []): Station
    {
        return Station::create($values + ['vendor_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Global operations', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    public function test_only_current_admin_web_sessions_can_read_the_private_map(): void
    {
        $url = '/api/admin/operations/map';
        $this->getJson($url)->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_admin_second_factor_is_required_when_the_existing_gate_is_enforced(): void
    {
        config(['admin_security.enforce_in_local_tests' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/admin/operations/map')
            ->assertForbidden()->assertJsonPath('admin_second_factor_required', true);
    }

    public function test_bearer_token_cannot_open_an_admin_web_map(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withToken($admin->createToken('must-not-open-map')->plainTextToken)->getJson('/api/admin/operations/map')->assertUnauthorized();
    }

    public function test_map_paginates_before_projection_and_exposes_only_inventory_fields(): void
    {
        for ($n = 0; $n < 27; $n++) {
            $this->station(['name' => 'SYNTHETIC-MAP-'.$n]);
        }
        $this->station(['name' => 'Invalid latitude', 'latitude' => 91]);
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->getJson('/api/admin/operations/map')->assertOk()
            ->assertJsonPath('total', 27)->assertJsonCount(25, 'data')->assertJsonPath('scope', 'platform_inventory_all_companies')
            ->assertJsonPath('data.0.availability', 'unknown')->assertJsonPath('data.0.remote_control', false)
            ->assertJsonPath('charging_authority_granted', false)->assertJsonPath('payment_authority_granted', false);
        $this->assertSame(['id', 'name', 'country', 'timezone', 'latitude', 'longitude', 'source', 'company', 'charger_count', 'mapped_connector_count', 'availability', 'physical_availability_verified', 'remote_control'], array_keys($response->json('data.0')));
        $this->getJson('/api/admin/operations/map?page=2')->assertOk()->assertJsonCount(2, 'data');
        foreach (['password', 'email', 'token', 'trade_license_number', 'identifier'] as $key) {
            $response->assertJsonMissingPath('data.0.'.$key);
        }
    }

    public function test_literal_text_and_antimeridian_viewport_filters_are_applied_before_pagination(): void
    {
        $this->station(['name' => 'Literal_%', 'latitude' => 1, 'longitude' => 179]);
        $this->station(['name' => 'LiteralXY', 'latitude' => 1, 'longitude' => -179]);
        $this->station(['name' => 'Outside viewport', 'latitude' => 1, 'longitude' => 0]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/operations/map?south=0&north=2&west=170&east=-170')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/operations/map?q=Literal_%25')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Literal_%');
        $this->getJson('/api/admin/operations/map?south=0&north=2&west=-10&east=10')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/operations/map?recent_source_status=Available')->assertOk()->assertJsonPath('total', 0);
        foreach (['south=0', 'south=3&north=2&west=0&east=1', 'country=ae', 'page=0', 'recent_source_status=online', 'west=NaN&east=1&south=0&north=1'] as $query) {
            $this->getJson('/api/admin/operations/map?'.$query)->assertUnprocessable();
        }
    }

    public function test_mismatched_company_ownership_is_not_presented_as_valid_inventory(): void
    {
        $station = $this->station();
        $company = VendorCompany::create(['owner_id' => User::factory()->create()->id, 'legal_name' => 'Private mismatch', 'country' => 'AE', 'trade_license_number' => 'SECRET', 'license_expires_on' => now()->addYear(), 'status' => 'approved']);
        VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Corrupt association', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'PRIVATE address', 'latitude' => 25, 'longitude' => 55]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/admin/operations/map')->assertOk()->assertJsonPath('data.0.company', null)->assertJsonPath('data.0.charger_count', 0);
        $this->getJson('/api/admin/operations/map?company_id='.$company->id)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_stale_admin_snapshot_is_rejected_before_any_private_inventory_read(): void
    {
        foreach (['role' => 'customer', 'account_status' => 'suspended', 'auth_epoch' => 3, 'password' => bcrypt('ChangedSyntheticPassword9!')] as $field => $value) {
            $admin = User::factory()->create(['role' => 'admin', 'auth_epoch' => 0]);
            DB::table('users')->where('id', $admin->id)->update([$field => $value]);
            $request = Request::create('/api/admin/operations/map');
            $request->setUserResolver(fn () => $admin);
            try {
                app(AdminOperationsMapController::class)->index($request);
                $this->fail('Stale Admin read accepted.');
            } catch (HttpException $e) {
                $this->assertSame(in_array($field, ['auth_epoch', 'password'], true) ? 409 : 403, $e->getStatusCode());
            }
        }
    }
}
