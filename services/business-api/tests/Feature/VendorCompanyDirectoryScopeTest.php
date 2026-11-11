<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorCompanyDirectoryScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_only_staff_can_select_company_without_reading_private_company_fields(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'vendor']);
        $stranger = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic fleet operator',
            'country' => 'AE',
            'status' => 'approved',
            'license_expires_on' => now()->addYear()->toDateString(),
            'trade_license_number' => 'PRIVATE-LICENSE-123',
        ]);
        $membership = VendorCompanyStaff::create([
            'company_id' => $company->id,
            'user_id' => $staff->id,
            'status' => 'active',
            'permissions' => ['inventory.read'],
            'accepted_at' => now(),
        ]);

        $url = '/api/vendor-ops/companies';

        $this->actingAs($stranger, 'web')
            ->getJson($url)->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($staff, 'web')
            ->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $company->id)
            ->assertJsonPath('data.0.legal_name', $company->legal_name)
            ->assertJsonPath('data.0.capabilities.inventory_read', true)
            ->assertJsonPath('data.0.capabilities.company_read', false)
            ->assertJsonPath('data.0.restricted_directory_entry', true)
            ->assertJsonMissingPath('data.0.owner_id')
            ->assertJsonMissingPath('data.0.license_expires_on')
            ->assertJsonMissingPath('data.0.reviewed_at')
            ->assertJsonMissingPath('data.0.trade_license_number');

        // A real company.read scope restores ordinary company metadata, not KYC secrets.
        $membership->update(['permissions' => ['company.read', 'inventory.read']]);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.0.owner_id', $owner->id)
            ->assertJsonPath('data.0.capabilities.company_read', true)
            ->assertJsonMissingPath('data.0.trade_license_number');

        // Neither a pending invitation nor a miswritten active Admin-held grant
        // may make the company visible through the directory.
        $membership->update(['status' => 'invited']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $membership->update(['status' => 'active', 'admin_revoked_at' => now()]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($owner, 'web')->getJson($url)->assertOk()
            ->assertJsonPath('data.0.owner_id', $owner->id)
            ->assertJsonPath('data.0.capabilities.owner', true);
    }
}
