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
            'admin_revoked_at' => $member->fresh()->admin_revoked_at,
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
            ->assertJsonPath('data.0.reason', 'Verified emergency staff access investigation')
            ->assertJsonPath('data.0.action', 'revoked');
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


    public function test_owner_cannot_reinvite_during_admin_hold_and_release_requires_fresh_consent(): void
    {
        [$admin, $owner, $staff, $company, $member] = $this->fixture();
        $revoke = '/api/admin/permission-grants/'.$member->id.'/revoke';
        $release = '/api/admin/permission-grants/'.$member->id.'/release';
        $invite = '/api/vendor-ops/companies/'.$company->id.'/staff';
        $this->actingAs($admin)->postJson($revoke, $this->payload())->assertOk()
            ->assertJsonPath('admin_emergency_hold', true);

        // Existing owner/invite API cannot sidestep an Admin emergency hold.
        $invitePayload = ['email' => $staff->email, 'permissions' => ['company.read']];
        $this->actingAs($owner)->postJson($invite, $invitePayload)->assertStatus(409);
        $this->assertDatabaseHas('vendor_company_staff', [
            'id' => $member->id, 'status' => 'revoked', 'invitation_version' => 2,
        ]);
        $this->actingAs($staff)->postJson(
            '/api/vendor-ops/staff-invitations/'.$member->id.'/accept',
            ['expected_version' => 2]
        )->assertStatus(409);

        $this->postJson($release, $this->payload(2))->assertForbidden();
        $this->actingAs($admin)->postJson($release, $this->payload(1))
            ->assertStatus(409);
        $this->postJson($release, $this->payload(2, 'Admin completed independent evidence review'))
            ->assertOk()
            ->assertJsonPath('status', 'revoked')
            ->assertJsonPath('admin_emergency_hold', false)
            ->assertJsonPath('requires_fresh_invitation', true)
            ->assertJsonPath('invitation_version', 3);
        $this->getJson('/api/admin/permission-grants?status=revoked')
            ->assertOk()->assertJsonPath('data.0.admin_emergency_hold', false);

        // Release does not silently restore prior privileges or token scopes.
        $this->actingAs($staff)->getJson('/api/vendor-ops/companies/'.$company->id)
            ->assertNotFound();
        $this->actingAs($owner)->postJson($invite, $invitePayload)->assertAccepted();
        $this->assertDatabaseHas('vendor_company_staff', [
            'id' => $member->id, 'status' => 'invited', 'invitation_version' => 4,
        ]);
        $this->actingAs($staff)->postJson(
            '/api/vendor-ops/staff-invitations/'.$member->id.'/accept',
            ['expected_version' => 3]
        )->assertStatus(409);
        $this->postJson(
            '/api/vendor-ops/staff-invitations/'.$member->id.'/accept',
            ['expected_version' => 4]
        )->assertOk();
        $this->getJson('/api/vendor-ops/companies/'.$company->id)->assertOk();
        $this->actingAs($admin)->getJson(
            '/api/admin/permission-grants/'.$member->id.'/revocations'
        )->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.action', 'released')
            ->assertJsonPath('data.1.action', 'revoked');
    }

    public function test_release_is_version_bound_and_non_admin_cannot_erase_security_hold(): void
    {
        [$admin, $owner, , , $member] = $this->fixture('invited');
        $release = '/api/admin/permission-grants/'.$member->id.'/release';
        $this->actingAs($admin)->postJson($release, $this->payload())->assertStatus(409);
        $this->postJson('/api/admin/permission-grants/'.$member->id.'/revoke',
            $this->payload())->assertOk();
        $this->postJson($release, $this->payload(2, 'short'))->assertUnprocessable();
        $this->actingAs($owner)->postJson($release, $this->payload(2))->assertForbidden();
        $this->actingAs($admin)->postJson($release, $this->payload(2))->assertOk();
        $this->postJson($release, $this->payload(3))->assertStatus(409);
        $this->assertDatabaseCount('admin_permission_revocations', 2);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'admin.permission.hold_released',
            'subject' => (string) $member->id,
        ]);
    }

    public function test_emergency_hold_is_checked_at_each_company_access_even_if_status_is_inconsistent(): void
    {
        [$admin, $owner, $staff, $company, $member] = $this->fixture();
        $this->actingAs($admin)->postJson(
            '/api/admin/permission-grants/'.$member->id.'/revoke',
            $this->payload()
        )->assertOk();

        // Simulate a separate stale writer or erroneous legacy SQL update:
        // the Admin hold must override an accidentally reactivated status.
        DB::table('vendor_company_staff')->where('id', $member->id)
            ->update(['status' => 'active']);
        $this->actingAs($staff)->getJson(
            '/api/vendor-ops/companies/'.$company->id
        )->assertNotFound();
        $this->getJson('/api/vendor-ops/companies')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($owner)->getJson(
            '/api/vendor-ops/companies/'.$company->id
        )->assertOk();
        $this->assertNotNull($member->fresh()->admin_revoked_at);
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
