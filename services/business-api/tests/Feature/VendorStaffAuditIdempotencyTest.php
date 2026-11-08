<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VendorStaffAuditIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! collect(Route::getRoutes())->contains(fn ($route) =>
            $route->uri() === 'api/vendor-ops/companies' && in_array('GET', $route->methods()))) {
            Route::middleware(['web', 'auth'])->prefix('api/vendor-ops')
                ->group(base_path('routes/vendor_ops.php'));
        }
    }

    private function countAction(string $action): int
    {
        return DB::table('audit_events')->where('action', 'vendor_ops.'.$action)->count();
    }

    public function test_repeated_owner_invites_and_revoke_are_audited_only_when_grant_changes(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'customer']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic staff audit operator',
            'country' => 'AE',
            'trade_license_number' => 'TEST-PRIVATE',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $base = '/api/vendor-ops/companies/'.$company->id.'/staff';

        $this->actingAs($owner, 'web')
            ->postJson($base, ['email' => $staff->email, 'permissions' => ['inventory.read']])
            ->assertAccepted();
        $membership = VendorCompanyStaff::sole();
        $this->assertSame(1, $this->countAction('staff.invited'));
        $this->assertSame(1, $membership->invitation_version);

        // Existing invitation is unchanged; do not report it as a new grant.
        $this->postJson($base, ['email' => $staff->email, 'permissions' => ['inventory.write']])
            ->assertAccepted();
        $this->assertSame(1, $this->countAction('staff.invited'));
        $this->assertSame(['inventory.read'], $membership->fresh()->permissions);

        $this->actingAs($staff, 'web')
            ->postJson('/api/vendor-ops/staff-invitations/'.$membership->id.'/accept',
                ['expected_version' => 1])
            ->assertOk();
        $this->actingAs($owner, 'web')
            ->postJson($base, ['email' => $staff->email, 'permissions' => ['inventory.write']])
            ->assertAccepted();
        $this->assertSame(1, $this->countAction('staff.invited'));
        $this->assertSame('active', $membership->fresh()->status);

        $this->deleteJson($base.'/'.$membership->id)->assertNoContent();
        $this->assertSame(1, $this->countAction('staff.revoked'));
        $version = $membership->fresh()->invitation_version;
        $this->deleteJson($base.'/'.$membership->id)->assertNoContent();
        $this->assertSame(1, $this->countAction('staff.revoked'));
        $this->assertSame($version, $membership->fresh()->invitation_version);

        $this->postJson($base, ['email' => $staff->email, 'permissions' => ['inventory.write']])
            ->assertAccepted();
        $this->assertSame(2, $this->countAction('staff.invited'));
        $this->assertSame($version + 1, $membership->fresh()->invitation_version);
        $this->assertSame('invited', $membership->fresh()->status);
        $this->assertSame(['inventory.write'], $membership->fresh()->permissions);
    }

    public function test_admin_hold_blocks_reinvite_even_if_legacy_writer_restored_active_status(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'customer']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic held operator',
            'country' => 'AE',
            'trade_license_number' => 'TEST-PRIVATE',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $member = VendorCompanyStaff::create([
            'company_id' => $company->id,
            'user_id' => $staff->id,
            'status' => 'active',
            'permissions' => ['inventory.read'],
            'admin_revoked_at' => now(),
        ]);
        $this->actingAs($owner, 'web')
            ->postJson('/api/vendor-ops/companies/'.$company->id.'/staff', [
                'email' => $staff->email,
                'permissions' => ['inventory.write'],
            ])->assertConflict();

        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame(['inventory.read'], $member->fresh()->permissions);
        $this->assertSame(0, $this->countAction('staff.invited'));
    }

    public function test_staff_invite_rejects_duplicate_or_oversized_permission_arrays_without_grant_or_audit(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'customer']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic scope validation operator',
            'country' => 'AE',
            'trade_license_number' => 'TEST-PRIVATE',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'approved',
        ]);
        $path = '/api/vendor-ops/companies/'.$company->id.'/staff';

        $this->actingAs($owner, 'web')->postJson($path, [
            'email' => $staff->email,
            'permissions' => ['inventory.read', 'inventory.read'],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');

        $this->postJson($path, [
            'email' => $staff->email,
            'permissions' => [
                'company.read', 'inventory.read', 'inventory.write', 'company.read',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions');

        $this->assertDatabaseCount('vendor_company_staff', 0);
        $this->assertSame(0, $this->countAction('staff.invited'));

        $this->postJson($path, [
            'email' => $staff->email,
            'permissions' => ['company.read', 'inventory.read', 'inventory.write'],
        ])->assertAccepted();
        $membership = VendorCompanyStaff::where('company_id', $company->id)
            ->where('user_id', $staff->id)->firstOrFail();
        $this->assertSame(
            ['company.read', 'inventory.read', 'inventory.write'],
            $membership->permissions
        );
        $this->assertSame(1, $this->countAction('staff.invited'));
    }

}
