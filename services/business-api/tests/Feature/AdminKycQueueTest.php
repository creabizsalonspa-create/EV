<?php

namespace Tests\Feature;

use App\Http\Controllers\VendorOpsController;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorKycDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_license_watchlist_uses_country_local_date_and_filters_before_pagination(): void
    {
        // At this instant the UAE is already on the 16th while Toronto is
        // still on the 15th. Expiry is at the end of each local license day.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 21:00:00', 'UTC'));
        DB::table('regions')->updateOrInsert(['country' => 'CA'], [
            'currency' => 'CAD',
            'minor_units' => 2,
            'timezone' => 'America/Toronto',
            'tax_bps' => null,
            'commerce_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $aeExpired = $this->company($owner, 'approved', 'AE');
        $aeExpired->update(['license_expires_on' => '2026-11-15']);
        $aeDue = $this->company($owner, 'approved', 'AE');
        $aeDue->update(['license_expires_on' => '2026-11-16']);
        $aeCurrent = $this->company($owner, 'approved', 'AE');
        $aeCurrent->update(['license_expires_on' => '2026-12-17']);
        $caDue = $this->company($owner, 'approved', 'CA');
        $caDue->update(['license_expires_on' => '2026-11-15']);
        $caExpired = $this->company($owner, 'approved', 'CA');
        $caExpired->update(['license_expires_on' => '2026-11-14']);

        $this->actingAs($owner)->getJson(
            '/api/admin/kyc-queue?status=approved&license_health=expired'
        )->assertForbidden();

        $expired = $this->actingAs($admin)->getJson(
            '/api/admin/kyc-queue?status=approved&license_health=expired'
        )->assertOk()->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertEqualsCanonicalizing(
            [$aeExpired->id, $caExpired->id],
            array_column($expired->json('data'), 'id')
        );
        $expired->assertJsonPath('data.0.license_health', 'expired')
            ->assertJsonMissingPath('data.0.trade_license_number');

        $due = $this->getJson('/api/admin/kyc-queue?status=approved&license_health=due_30_days')
            ->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing(
            [$aeDue->id, $caDue->id],
            array_column($due->json('data'), 'id')
        );
        $this->getJson('/api/admin/kyc-queue?status=approved&license_health=current')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $aeCurrent->id);
        $this->getJson('/api/admin/kyc-queue?status=approved&license_health=unknown')
            ->assertOk()->assertJsonPath('total', 0);

        $this->getJson('/api/admin/kyc-queue?status=approved&country=CA&license_health=due_30_days')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $caDue->id)
            ->assertJsonPath('data.0.license_local_date', '2026-11-15');
        $this->getJson('/api/admin/kyc-queue?status=approved&country=AE&license_health=due_30_days')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $aeDue->id)
            ->assertJsonPath('data.0.license_local_date', '2026-11-16');

        $this->getJson('/api/admin/kyc-queue?license_health=not_real')
            ->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'admin.private_list.kyc_viewed',
            'subject' => 'status:approved;license:expired',
        ]);
    }

    public function test_private_company_directory_and_detail_reject_stale_admin_sessions(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $directory = '/api/vendor-ops/companies';
        $detail = $directory.'/'.$company->id;
        $this->actingAs($admin, 'web');
        $this->getJson($directory)->assertOk()->assertJsonPath('total', 1);
        $this->getJson($detail)->assertOk()->assertJsonPath('id', $company->id);
        $this->getJson($directory.'?page=100001')->assertUnprocessable();

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->getJson($directory)->assertForbidden();
        $this->getJson($detail)->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin', 'account_status' => 'suspended',
        ]);
        $this->getJson($directory)->assertForbidden();
        $this->getJson($detail)->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active', 'auth_epoch' => 1,
        ]);
        $this->getJson($directory)->assertStatus(409);
        $this->getJson($detail)->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0, 'password' => Hash::make('new-reviewer-secret'),
        ]);
        $this->getJson($directory)->assertStatus(409);
        $this->getJson($detail)->assertStatus(409);

        // A real owner retains their own private company detail and list.
        $this->actingAs($owner, 'web')->getJson($detail)
            ->assertOk()->assertJsonPath('id', $company->id);
        $this->getJson($directory)->assertOk()->assertJsonPath('total', 1);
    }

    public function test_reassigned_company_cannot_use_stale_route_binding_to_disclose_private_kyc(): void
    {
        $originalOwner = User::factory()->create(['role' => 'vendor']);
        $newOwner = User::factory()->create(['role' => 'vendor']);
        $company = $this->company($originalOwner);
        $document = VendorKycDocument::create([
            'company_id' => $company->id,
            'uploaded_by' => $originalOwner->id,
            'kind' => 'trade_license',
            'mime_type' => 'application/pdf',
            'size_bytes' => 0,
            'sha256' => str_repeat('0', 64),
            'storage_path' => 'vendor-kyc/never-authorized.enc',
        ]);
        // The PHP model is deliberately left stale to emulate reassignment
        // after Laravel route binding, before the controller starts reading.
        DB::table('vendor_companies')->where('id', $company->id)
            ->update(['owner_id' => $newOwner->id]);
        $request = Request::create('/api/vendor-ops/companies/'.$company->id, 'GET');
        $request->setUserResolver(fn () => $originalOwner);
        $controller = app(VendorOpsController::class);

        foreach ([
            fn () => $controller->company($request, $company),
            fn () => $controller->reviews($request, $company),
            fn () => $controller->documents($request, $company),
            fn () => $controller->download($request, $company, $document),
        ] as $privateRead) {
            try {
                $privateRead();
                $this->fail('Stale company owner disclosed private KYC information.');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
        $this->assertSame(0, DB::table('audit_events')->count());

        // The newly authorized owner can still inspect their own company.
        $this->actingAs($newOwner, 'web')
            ->getJson('/api/vendor-ops/companies/'.$company->id)
            ->assertOk()->assertJsonPath('id', $company->id);
    }

    public function test_private_kyc_history_and_evidence_reads_fail_after_admin_credential_revocation(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        Storage::fake('local');
        $contents = "%PDF-1.4\nPRIVATE-REVIEW-EVIDENCE";
        $path = 'vendor-kyc/current-reviewer-evidence.enc';
        Storage::disk('local')->put($path, Crypt::encryptString($contents));
        $document = VendorKycDocument::create([
            'company_id' => $company->id,
            'uploaded_by' => $owner->id,
            'kind' => 'trade_license',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_path' => $path,
        ]);
        $base = '/api/vendor-ops/companies/'.$company->id;
        $readPaths = [
            $base.'/reviews',
            $base.'/documents',
            $base.'/documents/'.$document->id,
        ];

        $this->actingAs($admin, 'web');
        $this->getJson($readPaths[0])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($readPaths[1])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonMissingPath('data.0.storage_path')
            ->assertJsonMissingPath('data.0.sha256');
        $this->get($readPaths[2])->assertOk()
            ->assertSee('PRIVATE-REVIEW-EVIDENCE', false);
        $positiveAuditCount = DB::table('audit_events')->count();

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        foreach ($readPaths as $pathToRead) {
            $this->get($pathToRead)->assertForbidden();
        }

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin', 'account_status' => 'suspended',
        ]);
        foreach ($readPaths as $pathToRead) {
            $this->get($pathToRead)->assertForbidden();
        }

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active', 'auth_epoch' => 1,
        ]);
        foreach ($readPaths as $pathToRead) {
            $this->get($pathToRead)->assertStatus(409);
        }

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0, 'password' => Hash::make('rotated-reviewer-password'),
        ]);
        foreach ($readPaths as $pathToRead) {
            $this->get($pathToRead)->assertStatus(409);
        }
        $this->assertSame($positiveAuditCount, DB::table('audit_events')->count());

        // Owner access to their own evidence remains available.
        $this->actingAs($owner, 'web')->get($readPaths[2])->assertOk();
        $this->getJson($readPaths[0])->assertOk();
    }

    public function test_private_kyc_history_and_document_lists_reject_unbounded_pages(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $base = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($admin, 'web');
        foreach (['/reviews', '/documents'] as $suffix) {
            foreach (['0', '-2', 'invalid', '100001'] as $badPage) {
                $this->getJson($base.$suffix.'?page='.$badPage)->assertUnprocessable();
            }
        }
        $this->assertSame(0, DB::table('audit_events')
            ->whereIn('action', ['vendor_ops.review.history_viewed', 'vendor_ops.document.metadata_viewed'])->count());
        $this->getJson($base.'/reviews?page=1')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($base.'/documents?page=1')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_kyc_review_rechecks_revoked_admin_identity_inside_transaction(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $route = '/api/vendor-ops/companies/'.$company->id.'/review';
        $payload = [
            'status' => 'changes_requested',
            'reason' => 'Independent license evidence needs correction',
        ];

        // Keep the authenticated request's prior User object alive while
        // the database authority has changed underneath it.
        $this->actingAs($admin, 'web');
        DB::table('users')->where('id', $admin->id)->update(['account_status' => 'suspended']);
        $this->postJson($route, $payload)->assertForbidden();
        $this->assertDatabaseHas('vendor_companies', ['id' => $company->id, 'status' => 'submitted']);
        $this->assertDatabaseCount('vendor_company_reviews', 0);

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active',
            'auth_epoch' => 1,
        ]);
        $this->postJson($route, $payload)->assertStatus(409);
        $this->assertDatabaseHas('vendor_companies', ['id' => $company->id, 'status' => 'submitted']);
        $this->assertDatabaseCount('vendor_company_reviews', 0);

        // No review decision or positive audit is manufactured by denial.
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'vendor_ops.company.changes_requested',
            'subject' => (string) $company->id,
        ]);
    }

    public function test_private_kyc_queue_rechecks_live_admin_authority_before_owner_data_or_audit(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $this->actingAs($admin, 'web');

        DB::table('users')->where('id', $admin->id)->update(['role' => 'vendor']);
        $this->getJson('/api/admin/kyc-queue')->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'role' => 'admin', 'account_status' => 'suspended',
        ]);
        $this->getJson('/api/admin/kyc-queue')->assertForbidden();

        DB::table('users')->where('id', $admin->id)->update([
            'account_status' => 'active', 'auth_epoch' => 1,
        ]);
        $this->getJson('/api/admin/kyc-queue')->assertStatus(409);

        DB::table('users')->where('id', $admin->id)->update([
            'auth_epoch' => 0, 'password' => Hash::make('new-admin-password'),
        ]);
        $this->getJson('/api/admin/kyc-queue')->assertStatus(409);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'admin.private_list.kyc_viewed')->count());

        $freshAdmin = User::factory()->create(['role' => 'admin']);
        $result = $this->actingAs($freshAdmin, 'web')->getJson('/api/admin/kyc-queue')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $company->id)
            ->assertJsonPath('data.0.manual_review_required', true);
        $result->assertJsonMissingPath('data.0.trade_license_number');
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $freshAdmin->id,
            'action' => 'admin.private_list.kyc_viewed',
            'subject' => 'status:submitted',
        ]);
    }
}
