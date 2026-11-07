<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSensitiveListReadAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_admin_lists_are_private_and_log_actual_successful_readers_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create([
            'owner_id' => $vendor->id,
            'legal_name' => 'Synthetic private operator',
            'country' => 'AE',
            'status' => 'submitted',
            'license_expires_on' => now()->addYear()->toDateString(),
            'trade_license_number' => 'CONFIDENTIAL-LICENSE-872',
        ]);
        $reads = [
            ['/api/admin/accounts?role=vendor',
                'admin.private_list.accounts_viewed', 'role:vendor'],
            ['/api/admin/kyc-queue?status=submitted',
                'admin.private_list.kyc_viewed', 'status:submitted'],
            ['/api/admin/permission-grants?company_id='.$company->id,
                'admin.private_list.staff_viewed', 'company:'.$company->id],
        ];

        foreach ($reads as [$route]) {
            $this->getJson($route)->assertUnauthorized();
        }
        $this->actingAs($vendor);
        foreach ($reads as [$route]) {
            $this->getJson($route)->assertForbidden();
        }
        $this->assertDatabaseCount('audit_events', 0);

        $this->actingAs($admin);
        foreach ($reads as [$route, $action, $subject]) {
            $this->getJson($route)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertDatabaseHas('audit_events', [
                'actor_id' => $admin->id,
                'action' => $action,
                'subject' => $subject,
            ]);
        }

        $this->assertDatabaseCount('audit_events', 3);
        $events = DB::table('audit_events')->orderBy('id')->get()->toJson();
        $this->assertStringNotContainsString('CONFIDENTIAL-LICENSE-872', $events);
        $this->assertStringNotContainsString($vendor->email, $events);

        $this->getJson('/api/admin/accounts?role=invalid')->assertUnprocessable();
        $this->getJson('/api/admin/kyc-queue?status=invalid')->assertUnprocessable();
        $this->getJson('/api/admin/permission-grants?status=invalid')->assertUnprocessable();
        $this->assertDatabaseCount('audit_events', 3);

        $admin->forceFill(['account_status' => 'suspended'])->save();
        foreach ($reads as [$route]) {
            $this->actingAs($admin->fresh())->getJson($route)->assertForbidden();
        }
        $this->assertDatabaseCount('audit_events', 3);
    }
}
