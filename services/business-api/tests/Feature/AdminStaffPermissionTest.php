<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminStaffPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $status = 'active'): array
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Private operator '.$owner->id,
            'country' => 'AE',
            'status' => 'approved',
            'license_expires_on' => now()->addYear()->toDateString(),
            'trade_license_number' => 'PRIVATE-TRADE-LICENSE',
        ]);
        $member = VendorCompanyStaff::create([
            'company_id' => $company->id,
            'user_id' => $staff->id,
            'status' => $status,
            'permissions' => ['company.read', 'inventory.read'],
            'invitation_version' => 1,
            'accepted_at' => $status === 'active' ? now() : null,
        ]);

        return [$admin, $owner, $staff, $company, $member];
    }

    private function payload(int $version = 1, string $reason = 'Verified emergency staff access investigation'): array
    {
        return ['expected_version' => $version, 'reason' => $reason];
    }

    public function test_only_active_administrators_can_list_or_revoke_staff_grants(): void
    {
        [$admin, $owner, $staff, $company, $member] = $this->fixture();
        $url = '/api/admin/permission-grants/'.$member->id.'/revoke';

        $this->getJson('/api/admin/permission-grants')->assertUnauthorized();
        $this->postJson($url, $this->payload())->assertUnauthorized();
        $this->actingAs($staff)->getJson('/api/admin/permission-grants')->assertForbidden();
        $this->postJson($url, $this->payload())->assertForbidden();

        $this->actingAs($admin)->getJson('/api/admin/permission-grants')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.permissions.0', 'company.read')
            ->assertHeader('Cache-Control', 'no-store, private');

        $admin->forceFill(['account_status' => 'suspended'])->save();
        $this->actingAs($admin->fresh())->postJson($url, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('admin_permission_revocations', 0);
    }

    public function test_revocation_is_immediate_version_bound_private_and_audited(): void
    {
        [$admin, $owner, $staff, $company, $member] = $this->fixture();
        $companyUrl = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($staff)->getJson($companyUrl)->assertOk();

        $staffTokenId = $staff->createToken('mobile', ['platform'], now()->addDay())->accessToken->id;
        $adminUrl = '/api/admin/permission-grants/'.$member->id.'/revoke';
        $this->actingAs($admin)->postJson($adminUrl, $this->payload())
            ->assertOk()->assertJsonPath('id', $member->id)
            ->assertJsonPath('status', 'revoked')
            ->assertJsonPath('invitation_version', 2)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('vendor_company_staff', [
            'id' => $member->id, 'status' => 'revoked', 'invitation_version' => 2,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $staffTokenId]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.permission.revoked',
            'subject' => (string) $member->id,
        ]);
        $stored = DB::table('admin_permission_revocations')->first();
        $this->assertNotNull($stored);
        $this->assertSame(1, $stored->replaced_version);
        $this->assertStringNotContainsString('Verified emergency', $stored->reason_encrypted);

        $history = $this->getJson('/api/admin/permission-grants/'.$member->id.'/revocations')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reason', 'Verified emergency staff access investigation');
        $this->assertStringContainsString('no-store', $history->headers->get('Cache-Control'));

        $this->actingAs($staff)->getJson($companyUrl)->assertNotFound();
        $this->actingAs($owner)->getJson($companyUrl)->assertOk();
    }

    public function test_invited_membership_can_be_revoked_before_acceptance(): void
    {
        [$admin, , , , $membership] = $this->fixture('invited');
        $this->actingAs($admin)
            ->postJson('/api/admin/permission-grants/'.$membership->id.'/revoke', $this->payload())
            ->assertOk()->assertJsonPath('status', 'revoked');
        $this->assertDatabaseCount('admin_permission_revocations', 1);
    }

    public function test_stale_versions_duplicate_actions_and_short_reasons_cannot_revoke(): void
    {
        [$admin, , , , $membership] = $this->fixture();
        $url = '/api/admin/permission-grants/'.$membership->id.'/revoke';
        $this->actingAs($admin)->postJson($url, $this->payload(1, 'short'))->assertUnprocessable();
        $this->postJson($url, $this->payload(2))->assertStatus(409);
        $this->assertDatabaseCount('admin_permission_revocations', 0);
        $this->postJson($url, $this->payload())->assertOk();
        $this->postJson($url, $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('admin_permission_revocations', 1);
    }

    public function test_grants_are_bounded_filterable_and_do_not_serialize_company_secrets(): void
    {
        [$admin, , $staff, $company, $first] = $this->fixture();
        for ($i = 0; $i < 28; $i++) {
            $user = User::factory()->create(['role' => 'vendor']);
            VendorCompanyStaff::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'status' => 'active',
                'permissions' => ['company.read'],
                'invitation_version' => 1,
            ]);
        }

        $this->actingAs($admin)->getJson('/api/admin/permission-grants?status=active')
            ->assertOk()->assertJsonPath('total', 29)->assertJsonCount(25, 'data');
        $response = $this->getJson('/api/admin/permission-grants?page=2&company_id='.$company->id)
            ->assertOk()->assertJsonPath('total', 29)->assertJsonCount(4, 'data');
        $this->assertStringNotContainsString('PRIVATE-TRADE-LICENSE', $response->getContent());
        $response->assertJsonMissingPath('data.0.company.trade_license_number')
            ->assertJsonMissingPath('data.0.user.password');

        $this->getJson('/api/admin/permission-grants?status=bad')->assertUnprocessable();
        $this->getJson('/api/admin/permission-grants?company_id=-1')->assertUnprocessable();
        $this->getJson('/api/admin/permission-grants?page=100001')->assertUnprocessable();
    }

    public function test_revocation_history_is_private_to_admin(): void
    {
        [$admin, , $staff, , $membership] = $this->fixture();
        $history = '/api/admin/permission-grants/'.$membership->id.'/revocations';
        $this->actingAs($staff)->getJson($history)->assertForbidden();
        $this->actingAs($admin)->getJson($history)->assertOk()
            ->assertJsonPath('total', 0);
    }
}
