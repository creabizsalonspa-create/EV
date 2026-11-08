<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_authenticated_admin_can_list_private_accounts(): void
    {
        $this->getJson('/api/admin/accounts')->assertUnauthorized();

        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson('/api/admin/accounts')->assertForbidden();
        }

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/accounts')->assertOk();
    }

    public function test_directory_returns_only_allowlisted_fields_and_no_kyc_document_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->unverified()->create([
            'name' => 'Owned Vendor',
            'email' => 'account-directory-owner@example.test',
            'role' => 'vendor',
        ]);
        VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Source Vendor Business',
            'country' => 'AE',
            'trade_license_number' => 'PRIVATE-TRADE-LICENSE',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'submitted',
            'review_reason' => 'PRIVATE KYC REVIEW',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/accounts?q=account-directory-owner%40example.test');
        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $owner->id)
            ->assertJsonPath('data.0.email_verified', false)
            ->assertJsonPath('data.0.companies.0.status', 'submitted')
            ->assertJsonPath('data.0.companies.0.legal_name', 'Source Vendor Business')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.email_verified_at')
            ->assertJsonMissingPath('data.0.companies.0.trade_license_number')
            ->assertJsonMissingPath('data.0.companies.0.review_reason')
            ->assertJsonMissingPath('data.0.companies.0.storage_path');
        $body = $response->getContent();
        $this->assertStringNotContainsString('PRIVATE-TRADE-LICENSE', $body);
        $this->assertStringNotContainsString('PRIVATE KYC REVIEW', $body);
        $this->assertStringNotContainsString($owner->password, $body);
    }

    public function test_filters_role_and_verification_and_paginates_in_bounded_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $unverified = User::factory()->unverified()->create(['role' => 'vendor']);
        User::factory()->create(['role' => 'customer']);

        $this->actingAs($admin)->getJson('/api/admin/accounts?role=vendor&verified=no')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $unverified->id);
        $this->getJson('/api/admin/accounts?role=vendor&verified=yes')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $owner->id);
        $this->getJson('/api/admin/accounts?role=vendor')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $unverified->id);
        User::factory()->count(27)->create(['role' => 'customer']);
        $this->getJson('/api/admin/accounts?role=customer&page=1')->assertOk()->assertJsonCount(25, 'data');
        $this->getJson('/api/admin/accounts?role=customer&page=2')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_invalid_filters_are_rejected_and_directory_is_read_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/admin/accounts?role=superadmin')->assertUnprocessable();
        $this->getJson('/api/admin/accounts?verified=maybe')->assertUnprocessable();
        $this->getJson('/api/admin/accounts?page=0')->assertUnprocessable();
        $this->postJson('/api/admin/accounts', ['role' => 'admin'])->assertStatus(405);
    }
}
