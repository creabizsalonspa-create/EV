<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VendorStaffVerifiedEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! collect(Route::getRoutes())->contains(fn ($route) =>             $route->uri() === 'api/vendor-ops/companies'
            && in_array('GET', $route->methods()))) {
            Route::middleware(['web', 'auth'])->prefix('api/vendor-ops')
                ->group(base_path('routes/vendor_ops.php'));
        }
    }

    private function company(User $owner): VendorCompany
    {
        return VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic verified-email operator',
            'country' => 'AE',
            'status' => 'approved',
            'trade_license_number' => 'PRIVATE-LICENSE',
            'license_expires_on' => now()->addYear()->toDateString(),
        ]);
    }

    public function test_unverified_mailbox_cannot_view_accept_or_use_invitation_until_verified(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->unverified()->create(['role' => 'customer']);
        $company = $this->company($owner);
        $base = '/api/vendor-ops/companies/'.$company->id;

        $this->actingAs($owner)->postJson($base.'/staff', [
            'email' => $staff->email,
            'permissions' => ['company.read', 'inventory.read'],
        ])->assertAccepted();
        $membership = VendorCompanyStaff::where('company_id', $company->id)
            ->where('user_id', $staff->id)->firstOrFail();
        $this->assertSame('invited', $membership->status);

        $this->actingAs($staff)->getJson('/api/vendor-ops/staff-invitations')
            ->assertForbidden();
        $this->getJson('/api/vendor-ops/companies')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base)->assertNotFound();
        $this->postJson('/api/vendor-ops/staff-invitations/'.$membership->id.'/accept', [
            'expected_version' => $membership->invitation_version,
        ])->assertStatus(409);
        $this->assertSame('invited', $membership->fresh()->status);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'vendor_ops.staff.accepted')->count());

        $staff->forceFill(['email_verified_at' => now()])->save();
        $this->getJson('/api/vendor-ops/staff-invitations')->assertOk()
            ->assertJsonCount(1, 'data');
        $this->postJson('/api/vendor-ops/staff-invitations/'.$membership->id.'/accept', [
            'expected_version' => $membership->fresh()->invitation_version,
        ])->assertOk()->assertJsonPath('status', 'active');
        $this->getJson($base)->assertOk();
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'vendor_ops.staff.accepted')->count());
    }

    public function test_unverified_active_legacy_membership_has_no_access_to_company_or_inventory(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->unverified()->create(['role' => 'customer']);
        $company = $this->company($owner);
        VendorCompanyStaff::create([
            'company_id' => $company->id,
            'user_id' => $staff->id,
            'status' => 'active',
            'permissions' => ['company.read', 'inventory.read', 'inventory.write'],
        ]);

        $base = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($staff)->getJson('/api/vendor-ops/companies')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base)->assertNotFound();
        $this->getJson($base.'/sites')->assertNotFound();
        $this->getJson($base.'/catalog-stations')->assertNotFound();

        $staff->forceFill(['email_verified_at' => now()])->save();
        $this->getJson('/api/vendor-ops/companies')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base)->assertOk();
    }

    public function test_unverified_company_owner_retains_own_onboarding_access(): void
    {
        $owner = User::factory()->unverified()->create(['role' => 'customer']);
        $company = $this->company($owner);

        $this->actingAs($owner)->getJson('/api/vendor-ops/companies')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/vendor-ops/companies/'.$company->id)->assertOk()
            ->assertJsonPath('capabilities.owner', true);
    }
}
