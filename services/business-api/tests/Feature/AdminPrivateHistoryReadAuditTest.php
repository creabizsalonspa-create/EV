<?php

namespace Tests\Feature;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPrivateHistoryReadAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_history_access_records_actual_admin_without_copying_sensitive_notes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic Private Vendor',
            'country' => 'AE',
            'status' => 'approved',
            'license_expires_on' => now()->addYear()->toDateString(),
            'trade_license_number' => 'REDACT-ME-PRIVATE',
        ]);
        $membership = VendorCompanyStaff::create([
            'company_id' => $company->id,
            'user_id' => $staff->id,
            'status' => 'revoked',
            'permissions' => ['company.read'],
            'invitation_version' => 1,
        ]);
        $privacy = AccountPrivacyRequest::create([
            'user_id' => $owner->id,
            'kind' => 'erasure',
            'status' => 'pending',
            'request_details_encrypted' => Crypt::encryptString('Sensitive review case details'),
        ]);
        $routes = [
            ["/api/admin/accounts/{$owner->id}/moderations", 'admin.private_history.account_viewed', $owner->id],
            ["/api/admin/permission-grants/{$membership->id}/revocations", 'admin.private_history.staff_viewed', $membership->id],
            ["/api/admin/privacy-requests/{$privacy->id}/reviews", 'admin.private_history.privacy_viewed', $privacy->id],
        ];

        // Unauthorized attempts must neither disclose nor create privileged
        // access evidence claiming a successful review.
        foreach ($routes as [$url]) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->actingAs($staff);
        foreach ($routes as [$url]) {
            $this->getJson($url)->assertForbidden();
        }
        $this->assertDatabaseCount('audit_events', 0);

        $this->actingAs($admin);
        foreach ($routes as [$url, $event, $targetId]) {
            $this->getJson($url)->assertOk()
                ->assertJsonPath('total', 0)
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertDatabaseHas('audit_events', [
                'actor_id' => $admin->id,
                'action' => $event,
                'subject' => (string) $targetId,
            ]);
        }
        $events = DB::table('audit_events')->orderBy('id')->get();
        $this->assertCount(3, $events);
        $serialized = $events->toJson();
        $this->assertStringNotContainsString('Sensitive review case details', $serialized);
        $this->assertStringNotContainsString('REDACT-ME-PRIVATE', $serialized);

        $admin->forceFill(['account_status' => 'suspended'])->save();
        foreach ($routes as [$url]) {
            $this->actingAs($admin->fresh())->getJson($url)->assertForbidden();
        }
        $this->assertDatabaseCount('audit_events', 3);
    }
}
