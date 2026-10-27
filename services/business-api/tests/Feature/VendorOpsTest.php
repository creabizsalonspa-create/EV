<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorKycDocument;
use App\Models\VendorOps\VendorSite;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VendorOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'api/vendor-ops/companies' && in_array('GET', $route->methods()))) {
            Route::middleware(['web', 'auth'])->prefix('api/vendor-ops')->group(base_path('routes/vendor_ops.php'));
        }
        Storage::fake('local');
    }

    private function company(User $u, string $status = 'approved'): VendorCompany
    {
        return VendorCompany::create(['owner_id' => $u->id, 'legal_name' => 'Test operator', 'country' => 'AE', 'trade_license_number' => 'TEST-SECRET-LICENSE', 'license_expires_on' => now()->addYear()->format('Y-m-d'), 'status' => $status]);
    }

    private function site(VendorCompany $c): VendorSite
    {
        return VendorSite::create(['company_id' => $c->id, 'name' => 'Test site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Development location', 'latitude' => 25, 'longitude' => 55]);
    }

    private function charger(VendorCompany $c, VendorSite $s): VendorCharger
    {
        return VendorCharger::create(['company_id' => $c->id, 'site_id' => $s->id, 'asset_reference' => 'ASSET-1', 'manufacturer' => 'Simulator', 'model' => 'Development', 'source' => 'simulator']);
    }

    public function test_catalog_station_picker_is_scoped_to_company_writers_and_unlinked_owned_simulators(): void
    {
        $owner = User::factory()->create();
        $company = $this->company($owner);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Literal catalog choice', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $base = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($owner)->getJson($base.'/catalog-stations?q=Literal')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $station->id)->assertJsonMissingPath('data.0.vendor_id');
        $staff = User::factory()->create();
        VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'status' => 'active', 'permissions' => ['inventory.read']]);
        $this->actingAs($staff)->getJson($base.'/catalog-stations')->assertNotFound();
        VendorCompanyStaff::where('user_id', $staff->id)->firstOrFail()->update(['permissions' => ['inventory.write']]);
        $this->getJson($base.'/catalog-stations')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($base.'/sites', ['name' => 'Linked site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic address', 'latitude' => 25, 'longitude' => 55, 'station_id' => $station->id])->assertCreated();
        $this->getJson($base.'/catalog-stations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create())->getJson($base.'/catalog-stations')->assertNotFound();
    }

    public function test_onboarding_requires_private_documents_and_explicit_admin_review(): void
    {
        $applicant = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($applicant)->postJson('/api/vendor-ops/companies', ['legal_name' => 'Applicant company', 'country' => 'AE', 'trade_license_number' => 'CONFIDENTIAL-LICENSE', 'license_expires_on' => now()->addYear()->format('Y-m-d'), 'status' => 'approved'])->assertCreated()->assertJsonPath('status', 'draft')->assertJsonMissingPath('trade_license_number');
        $c = VendorCompany::first();
        $this->assertNotSame('CONFIDENTIAL-LICENSE', DB::table('vendor_companies')->value('trade_license_number'));
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/submit')->assertUnprocessable();
        $this->post('/api/vendor-ops/companies/'.$c->id.'/documents', ['kind' => 'trade_license', 'document' => UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\nTest private document")], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('storage_path');
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/submit')->assertOk()->assertJsonPath('status', 'submitted');
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/review', ['status' => 'approved', 'reason' => 'Self-approved'])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/vendor-ops/companies/'.$c->id.'/review', ['status' => 'approved', 'reason' => 'Internal workflow review completed; not hardware approval'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('physical_control_enabled', false);
        $this->assertSame('vendor', $applicant->fresh()->role);
        $this->assertDatabaseHas('audit_events', ['action' => 'vendor_ops.company.approved', 'actor_id' => $admin->id]);
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/review', ['status' => 'rejected', 'reason' => 'Invalid direct transition'])->assertConflict();
        $this->getJson('/api/vendor-ops/companies/'.$c->id.'/reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.from_status', 'submitted')->assertJsonPath('data.0.to_status', 'approved');
    }

    public function test_kyc_documents_are_not_public_or_visible_to_other_tenants_or_staff(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create();
        $c = $this->company($owner, 'draft');
        $otherCompany = $this->company($other);
        $this->actingAs($owner)->post('/api/vendor-ops/companies/'.$c->id.'/documents', ['kind' => 'trade_license', 'document' => UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\nPrivate file")], ['Accept' => 'application/json'])->assertCreated();
        $d = VendorKycDocument::first();
        $this->assertStringNotContainsString('Private file', Storage::disk('local')->get($d->getRawOriginal('storage_path')));
        $this->get('/api/vendor-ops/companies/'.$c->id.'/documents/'.$d->id)->assertOk()->assertContent("%PDF-1.4\nPrivate file")->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($other)->getJson('/api/vendor-ops/companies/'.$c->id.'/documents')->assertNotFound();
        $this->get('/api/vendor-ops/companies/'.$otherCompany->id.'/documents/'.$d->id)->assertNotFound();
        VendorCompanyStaff::create(['company_id' => $c->id, 'user_id' => $staff->id, 'permissions' => ['company.read', 'inventory.read'], 'status' => 'active']);
        $this->actingAs($staff)->getJson('/api/vendor-ops/companies/'.$c->id)->assertOk()->assertJsonMissingPath('trade_license_number');
        $this->getJson('/api/vendor-ops/companies/'.$c->id.'/documents')->assertNotFound();
        $this->get('/storage/'.$d->getRawOriginal('storage_path'))->assertForbidden();
    }

    public function test_staff_requires_consent_scope_and_cannot_grant_itself_permissions(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $reader = User::factory()->create();
        $writer = User::factory()->create();
        $c = $this->company($owner);
        $this->actingAs($owner)->postJson('/api/vendor-ops/companies/'.$c->id.'/staff', ['email' => $reader->email, 'permissions' => ['inventory.read']])->assertAccepted();
        $m = VendorCompanyStaff::first();
        $this->actingAs($reader)->getJson('/api/vendor-ops/companies/'.$c->id.'/sites')->assertNotFound();
        $this->postJson('/api/vendor-ops/staff-invitations/'.$m->id.'/accept')->assertOk();
        $this->getJson('/api/vendor-ops/companies/'.$c->id.'/sites')->assertOk();
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/sites', [])->assertNotFound();
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/staff', ['email' => $writer->email, 'permissions' => ['inventory.write']])->assertNotFound();
        $this->actingAs($writer)->postJson('/api/vendor-ops/staff-invitations/'.$m->id.'/accept')->assertNotFound();
        $this->actingAs($owner)->deleteJson('/api/vendor-ops/companies/'.$c->id.'/staff/'.$m->id)->assertNoContent();
        $this->actingAs($reader)->getJson('/api/vendor-ops/companies/'.$c->id.'/sites')->assertNotFound();
        $this->postJson('/api/vendor-ops/staff-invitations/'.$m->id.'/accept')->assertConflict();
    }

    public function test_inventory_hierarchy_is_tenant_scoped_and_does_not_enable_live_control(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $c = $this->company($owner);
        $foreign = $this->company($other);
        $foreignSite = $this->site($foreign);
        $siteData = ['name' => 'Site A', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Development site', 'latitude' => 25, 'longitude' => 55];
        $this->actingAs($owner)->postJson('/api/vendor-ops/companies/'.$c->id.'/sites', $siteData)->assertCreated();
        $s = VendorSite::where('company_id', $c->id)->first();
        $chargerData = ['asset_reference' => 'CHARGER-A', 'manufacturer' => 'Test', 'model' => 'Simulator', 'source' => 'direct_unverified', 'ocpp_protocol' => '2.0.1', 'remote_control_enabled' => true, 'integration_status' => 'hardware_verified'];
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/sites/'.$foreignSite->id.'/chargers', $chargerData)->assertNotFound();
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/sites/'.$s->id.'/chargers', $chargerData)->assertCreated()->assertJsonPath('remote_control_enabled', false)->assertJsonPath('integration_status', 'unverified');
        $charger = VendorCharger::first();
        $connectorData = ['connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 150, 'availability' => 'available', 'remote_control_enabled' => true];
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/chargers/'.$charger->id.'/connectors', $connectorData)->assertCreated()->assertJsonPath('availability', 'unknown')->assertJsonPath('remote_control_enabled', false);
        $connector = VendorConnector::first();
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/chargers/'.$charger->id.'/connectors', $connectorData)->assertUnprocessable();
        $this->actingAs($other)->getJson('/api/vendor-ops/companies/'.$c->id.'/chargers/'.$charger->id.'/connectors')->assertNotFound();
        $this->actingAs($owner)->patchJson('/api/vendor-ops/companies/'.$c->id.'/chargers/'.$charger->id.'/connectors/'.$connector->id.'/maintenance', ['maintenance_blocked' => true, 'maintenance_reason' => 'Scheduled inspection'])->assertOk()->assertJsonPath('maintenance_blocked', true)->assertJsonPath('availability', 'unknown');
    }

    public function test_unapproved_or_suspended_company_cannot_create_inventory(): void
    {
        $u = User::factory()->create(['role' => 'vendor']);
        $c = $this->company($u, 'draft');
        $v = ['name' => 'Site A', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Location', 'latitude' => 25, 'longitude' => 55];
        $this->actingAs($u)->postJson('/api/vendor-ops/companies/'.$c->id.'/sites', $v)->assertConflict();
        $c->update(['status' => 'suspended']);
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/sites', $v)->assertConflict();
    }

    public function test_company_edits_are_locked_after_submission_and_expired_license_blocks_inventory(): void
    {
        $u = User::factory()->create(['role' => 'vendor']);
        $c = $this->company($u, 'draft');
        $this->actingAs($u)->patchJson('/api/vendor-ops/companies/'.$c->id, ['legal_name' => 'Updated operator', 'status' => 'approved'])->assertOk()->assertJsonPath('legal_name', 'Updated operator')->assertJsonPath('status', 'draft');
        $c->update(['status' => 'submitted']);
        $this->patchJson('/api/vendor-ops/companies/'.$c->id, ['legal_name' => 'Unreviewed change'])->assertConflict();
        $c->update(['status' => 'approved', 'license_expires_on' => now()->subDay()->format('Y-m-d')]);
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/sites', ['name' => 'Expired operator site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Site', 'latitude' => 25, 'longitude' => 55])->assertConflict();
    }

    public function test_reinviting_revoked_staff_requires_fresh_acceptance_before_new_scope(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create();
        $c = $this->company($owner);
        $m = VendorCompanyStaff::create(['company_id' => $c->id, 'user_id' => $staff->id, 'permissions' => ['inventory.read'], 'status' => 'revoked']);
        $this->actingAs($owner)->postJson('/api/vendor-ops/companies/'.$c->id.'/staff', ['email' => $staff->email, 'permissions' => ['inventory.write']])->assertAccepted();
        $this->assertSame('invited', $m->fresh()->status);
        $this->actingAs($staff)->postJson('/api/vendor-ops/companies/'.$c->id.'/sites', [])->assertNotFound();
        $this->postJson('/api/vendor-ops/staff-invitations/'.$m->id.'/accept')->assertOk()->assertJsonPath('permissions.0', 'inventory.write');
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/sites', ['name' => 'Staff managed site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Site', 'latitude' => 25, 'longitude' => 55])->assertCreated();
    }

    public function test_arbitrary_files_and_invalid_permissions_are_rejected(): void
    {
        $u = User::factory()->create(['role' => 'vendor']);
        $c = $this->company($u, 'draft');
        $this->actingAs($u)->post('/api/vendor-ops/companies/'.$c->id.'/documents', ['kind' => 'trade_license', 'document' => UploadedFile::fake()->create('evil.html', 10, 'text/html')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson('/api/vendor-ops/companies/'.$c->id.'/staff', ['email' => 'test@example.test', 'permissions' => ['admin', 'physical.control']])->assertUnprocessable();
    }

    public function test_client_capabilities_follow_staff_consent_and_revocation_without_private_evidence(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'customer']);
        $company = $this->company($owner);
        $membership = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'permissions' => ['inventory.read']]);
        $this->actingAs($staff)->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/vendor-ops/staff-invitations')->assertOk()->assertJsonPath('data.0.company.legal_name', $company->legal_name)->assertJsonMissingPath('data.0.company.trade_license_number');
        $this->postJson('/api/vendor-ops/staff-invitations/'.$membership->id.'/accept')->assertOk();
        $this->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonPath('data.0.capabilities.inventory_read', true)->assertJsonPath('data.0.capabilities.inventory_write', false)->assertJsonPath('data.0.capabilities.private_evidence', false)->assertJsonPath('data.0.capabilities.company_read', false);
        $this->getJson('/api/vendor-ops/companies/'.$company->id.'/documents')->assertNotFound();
        $this->getJson('/api/vendor-ops/companies/'.$company->id.'/sites')->assertOk();
        $this->actingAs($owner)->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonPath('data.0.capabilities.owner', true)->assertJsonPath('data.0.capabilities.private_evidence', true);
        $this->deleteJson('/api/vendor-ops/companies/'.$company->id.'/staff/'.$membership->id)->assertNoContent();
        $this->actingAs($staff)->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_expired_approved_license_can_be_corrected_only_after_audited_review_request(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $company->update(['license_expires_on' => now()->subDay()->format('Y-m-d')]);
        $path = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($owner)->patchJson($path, ['license_expires_on' => now()->addYear()->format('Y-m-d')])->assertConflict();
        $this->actingAs($admin)->postJson($path.'/review', ['status' => 'changes_requested', 'reason' => 'Provide renewal evidence'])->assertOk()->assertJsonPath('status', 'changes_requested');
        $this->actingAs($owner)->patchJson($path, ['license_expires_on' => now()->addYear()->format('Y-m-d')])->assertOk();
        $this->post($path.'/documents', ['kind' => 'trade_license', 'document' => UploadedFile::fake()->create('renewal.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson($path.'/submit')->assertOk()->assertJsonPath('status', 'submitted');
        $this->postJson($path.'/sites', [])->assertConflict();
        $this->actingAs($admin)->postJson($path.'/review', ['status' => 'approved', 'reason' => 'Internal renewal review completed'])->assertOk();
        $this->getJson($path.'/reviews')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('vendor_companies', ['id' => $company->id, 'status' => 'approved']);
    }

    public function test_license_operational_gate_uses_the_configured_country_calendar_boundary(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = $this->company($owner);
        $company->update(['license_expires_on' => '2026-10-27']);
        $this->travelTo(CarbonImmutable::parse('2026-10-27T19:59:59Z'));
        $this->actingAs($owner)->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonPath('data.0.capabilities.inventory_operational', true);
        $this->travelTo(CarbonImmutable::parse('2026-10-27T20:00:00Z'));
        $this->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonPath('data.0.capabilities.inventory_operational', false);
        $this->postJson('/api/vendor-ops/companies/'.$company->id.'/sites', [])->assertConflict();
        DB::table('regions')->where('country', 'AE')->update(['timezone' => 'UTC']);
        $this->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonPath('data.0.capabilities.inventory_operational', true);
        $this->travelBack();
    }
}
