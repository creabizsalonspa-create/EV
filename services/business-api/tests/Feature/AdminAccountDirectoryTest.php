<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_private_account_directory_and_moderation_history_reject_stale_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($admin, 'web');

        DB::table('users')->where('id', $admin->id)
            ->update(['role' => 'customer']);
        $this->getJson('/api/admin/accounts')->assertForbidden();
        $this->getJson('/api/admin/accounts/'.$target->id.'/moderations')
            ->assertForbidden();

        DB::table('users')->where('id', $admin->id)
            ->update(['role' => 'admin', 'auth_epoch' => 1]);
        $this->getJson('/api/admin/accounts')->assertStatus(409);
        $this->getJson('/api/admin/accounts/'.$target->id.'/moderations')
            ->assertStatus(409);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_business_owner_filters_match_one_company_and_paginate_without_leaking_kyc(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $multi = User::factory()->create(['role' => 'vendor', 'name' => 'Multi operator']);
        $matches = User::factory()->create(['role' => 'vendor', 'name' => 'Matching operator']);
        $noCompany = User::factory()->create(['role' => 'vendor']);
        foreach ([
            [$multi, 'AE', 'approved', 'Alpha Dubai'],
            [$multi, 'CA', 'rejected', 'Beta Toronto'],
            [$matches, 'AE', 'rejected', 'Gamma Dubai'],
        ] as [$owner, $country, $status, $name]) {
            VendorCompany::create([
                'owner_id' => $owner->id,
                'legal_name' => $name,
                'country' => $country,
                'status' => $status,
                'trade_license_number' => 'SECRET-'.uniqid(),
                'review_reason' => 'PRIVATE CONFIDENTIAL NOTES',
                'license_expires_on' => now()->addYear()->toDateString(),
            ]);
        }

        $this->actingAs($noCompany)->getJson(
            '/api/admin/accounts?company_country=AE'
        )->assertForbidden();

        $this->actingAs($admin)->getJson('/api/admin/accounts?company_country=AE&company_status=rejected')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $matches->id);
        $this->getJson('/api/admin/accounts?company_country=CA&company_status=rejected')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $multi->id);
        $this->getJson('/api/admin/accounts?company_q=Dubai&company_status=approved')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $multi->id);
        $this->getJson('/api/admin/accounts?company_q=Toronto&company_country=AE')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/accounts?company_q=Gamma&company_country=AE&company_status=rejected&role=vendor')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $matches->id);

        $reply = $this->getJson('/api/admin/accounts?company_q=Gamma')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('data.0.companies.0.trade_license_number')
            ->assertJsonMissingPath('data.0.companies.0.review_reason');
        $this->assertStringNotContainsString('PRIVATE CONFIDENTIAL NOTES', $reply->getContent());
        $this->assertStringNotContainsString('SECRET-', $reply->getContent());

        for ($i = 0; $i < 27; $i++) {
            $owner = User::factory()->create(['role' => 'vendor']);
            VendorCompany::create([
                'owner_id' => $owner->id,
                'legal_name' => 'Retail QA '.$i,
                'country' => 'AE',
                'status' => 'submitted',
                'trade_license_number' => 'SECRET-'.uniqid(),
                'license_expires_on' => now()->addYear()->toDateString(),
            ]);
        }
        $this->getJson('/api/admin/accounts?company_q=Retail&company_country=AE&company_status=submitted&page=1')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonCount(25, 'data');
        $this->getJson('/api/admin/accounts?company_q=Retail&company_country=AE&company_status=submitted&page=2')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonCount(2, 'data');
    }

    public function test_business_directory_filters_reject_invalid_country_status_and_excessive_search(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/accounts?company_country=usa')->assertUnprocessable();
        $this->getJson('/api/admin/accounts?company_country=%2E%2E')->assertUnprocessable();
        $this->getJson('/api/admin/accounts?company_status=superapproved')->assertUnprocessable();
        $this->getJson('/api/admin/accounts?company_q='.str_repeat('x', 101))
            ->assertUnprocessable();
    }

    public function test_account_search_is_literal_case_insensitive_and_history_pages_are_bounded(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['name' => 'Review_X%!', 'email' => 'literal-target@example.test']);
        User::factory()->create(['name' => 'ReviewAXYZ', 'email' => 'other-target@example.test']);
        $this->actingAs($admin);
        foreach (['_', '%', '!', 'review_x%!'] as $search) {
            $this->getJson('/api/admin/accounts?q='.rawurlencode($search))
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $target->id);
        }
        $history = '/api/admin/accounts/'.$target->id.'/moderations';
        $before = DB::table('audit_events')->count();
        foreach (['0', '-1', '100001', '1.5'] as $page) {
            $this->getJson($history.'?page='.$page)->assertUnprocessable();
        }
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->getJson($history.'?page=1')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_business_name_search_is_case_insensitive_and_escapes_like_wildcards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $withSymbols = User::factory()->create(['role' => 'vendor']);
        $withoutSymbols = User::factory()->create(['role' => 'vendor']);

        foreach ([
            [$withSymbols, 'A_100% Warranty! LLC'],
            [$withoutSymbols, 'A1100X WARRANTYX LLC'],
        ] as [$owner, $name]) {
            VendorCompany::create([
                'owner_id' => $owner->id,
                'legal_name' => $name,
                'country' => 'AE',
                'trade_license_number' => 'SYMBOL-'.(string) $owner->id,
                'status' => 'submitted',
                'license_expires_on' => now()->addYear()->toDateString(),
            ]);
        }

        $this->actingAs($admin);
        foreach (['%', '_', '!'] as $symbol) {
            $this->getJson('/api/admin/accounts?company_q='.rawurlencode($symbol))
                ->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.id', $withSymbols->id);
        }

        $this->getJson('/api/admin/accounts?company_q=warranty')
            ->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/accounts?company_q='.rawurlencode('100% Warranty!'))
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $withSymbols->id);
    }
}
