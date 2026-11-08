<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorKycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminKycQueueTest extends TestCase
{
    use RefreshDatabase;

    private function company(User $owner, string $status = 'submitted', string $country = 'AE'): VendorCompany
    {
        return VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Reviewing Company '.uniqid(),
            'country' => $country,
            'status' => $status,
            'trade_license_number' => 'PRIVATE-TRADE-LICENSE-'.uniqid(),
            'license_expires_on' => now()->addYear()->toDateString(),
            'review_reason' => 'PRIVATE INTERNAL REVIEW',
        ]);
    }

    public function test_only_authenticated_active_admin_can_see_private_review_queue(): void
    {
        $this->getJson('/api/admin/kyc-queue')->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson('/api/admin/kyc-queue')->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/admin/kyc-queue')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertHeader('Cache-Control', 'no-store, private');

        $admin->forceFill(['account_status' => 'suspended'])->save();
        $this->actingAs($admin->fresh())->getJson('/api/admin/kyc-queue')->assertForbidden();
    }

    public function test_pending_queue_only_returns_whitelisted_owner_and_document_presence(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        VendorKycDocument::create([
            'company_id' => $company->id,
            'uploaded_by' => $owner->id,
            'kind' => 'trade_license',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256' => str_repeat('a', 64),
            'storage_path' => 'PRIVATE-KYC-STORAGE/PATH.enc',
        ]);
        $this->company($owner, 'draft');

        $reply = $this->actingAs($admin)->getJson('/api/admin/kyc-queue')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $company->id)
            ->assertJsonPath('data.0.status', 'submitted')
            ->assertJsonPath('data.0.owner.id', $owner->id)
            ->assertJsonPath('data.0.owner.account_status', 'active')
            ->assertJsonPath('data.0.document_count', 1)
            ->assertJsonPath('data.0.document_kinds.0', 'trade_license')
            ->assertJsonPath('data.0.manual_review_required', true)
            ->assertJsonPath('data.0.physical_control_enabled', false)
            ->assertJsonMissingPath('data.0.trade_license_number')
            ->assertJsonMissingPath('data.0.review_reason')
            ->assertJsonMissingPath('data.0.storage_path');
        $payload = $reply->getContent();
        $this->assertStringNotContainsString('PRIVATE-TRADE-LICENSE', $payload);
        $this->assertStringNotContainsString('PRIVATE INTERNAL REVIEW', $payload);
        $this->assertStringNotContainsString('PRIVATE-KYC-STORAGE', $payload);
    }

    public function test_queue_filters_status_country_and_paginates_in_bounded_order(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 28; $i++) {
            $this->company($owner, 'submitted');
        }
        $approved = $this->company($owner, 'approved', 'CA');

        $this->actingAs($admin)->getJson('/api/admin/kyc-queue')
            ->assertOk()->assertJsonPath('total', 28)->assertJsonCount(25, 'data');
        $this->getJson('/api/admin/kyc-queue?page=2')
            ->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/kyc-queue?status=approved&country=CA')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $approved->id);
        $this->getJson('/api/admin/kyc-queue?status=approved&country=AE')
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_direct_kyc_approval_requires_real_trade_license_document(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner, 'submitted');
        $this->actingAs($admin)
            ->postJson("/api/vendor-ops/companies/{$company->id}/review", [
                'status' => 'approved',
                'reason' => 'Reviewed company without trade-license evidence',
            ])->assertUnprocessable();

        $this->assertDatabaseHas('vendor_companies', [
            'id' => $company->id, 'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $owner->id, 'role' => 'customer', 'auth_epoch' => 0,
        ]);
        $this->assertDatabaseCount('vendor_company_reviews', 0);
    }

    public function test_direct_kyc_approval_rejects_suspended_company_owner(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $owner->forceFill(['account_status' => 'suspended'])->save();
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner, 'submitted');
        VendorKycDocument::create([
            'company_id' => $company->id,
            'uploaded_by' => $owner->id,
            'kind' => 'trade_license',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256' => str_repeat('a', 64),
            'storage_path' => 'test-kyc-file.enc',
        ]);
        $this->actingAs($admin)
            ->postJson("/api/vendor-ops/companies/{$company->id}/review", [
                'status' => 'approved',
                'reason' => 'Owner suspended during manual review',
            ])->assertStatus(409);
        $this->assertDatabaseHas('vendor_companies', [
            'id' => $company->id, 'status' => 'submitted',
        ]);
        $this->assertDatabaseCount('vendor_company_reviews', 0);
    }

    public function test_approved_company_customer_role_change_revokes_old_browser_epoch(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner, 'submitted');
        // Approval now requires evidence that exists and matches the
        // uploaded document digest, not just a fabricated database record.
        Storage::fake('local');
        $contents = "%PDF-1.4\nSYNTHETIC-VALID-TRADE-LICENSE";
        $path = 'vendor-kyc/test-valid-license.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($contents));
        VendorKycDocument::create([
            'company_id' => $company->id,
            'uploaded_by' => $owner->id,
            'kind' => 'trade_license',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_path' => $path,
        ]);
        $this->actingAs($admin)
            ->postJson("/api/vendor-ops/companies/{$company->id}/review", [
                'status' => 'approved',
                'reason' => 'Reviewed valid trade-license evidence',
            ])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('users', [
            'id' => $owner->id, 'role' => 'vendor', 'auth_epoch' => 1,
        ]);
        $this->assertDatabaseCount('vendor_company_reviews', 1);
        $this->actingAs($owner->fresh(), 'web')
            ->withSession(['account_auth_epoch' => 0])
            ->getJson('/api/vehicles')->assertUnauthorized();
    }

    public function test_malformed_filters_and_write_requests_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/kyc-queue?status=unexpected')->assertUnprocessable();
        $this->getJson('/api/admin/kyc-queue?country=../')->assertUnprocessable();
        $this->getJson('/api/admin/kyc-queue?page=100001')->assertUnprocessable();
        $this->postJson('/api/admin/kyc-queue', ['status' => 'approved'])->assertStatus(405);
    }
}
