<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyReview;
use App\Models\VendorOps\VendorKycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VendorKycReviewIntegrityTest extends TestCase
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
        Storage::fake('local');
    }

    private function company(User $owner): VendorCompany
    {
        return VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic License Holder',
            'country' => 'AE',
            'trade_license_number' => 'PRIVATE-LICENSE',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'draft',
        ]);
    }

    private function upload(User $owner, VendorCompany $company, string $bytes): VendorKycDocument
    {
        $this->actingAs($owner, 'web')->post(
            '/api/vendor-ops/companies/'.$company->id.'/documents',
            [
                'kind' => 'trade_license',
                'document' => UploadedFile::fake()->createWithContent('license.pdf', $bytes),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        return VendorKycDocument::where('company_id', $company->id)
            ->orderByDesc('id')->firstOrFail();
    }

    private function review(User $admin, VendorCompany $company, string $status)
    {
        return $this->actingAs($admin, 'web')->postJson(
            '/api/vendor-ops/companies/'.$company->id.'/review',
            ['status' => $status, 'reason' => 'Internal evidence review requires valid documents']
        );
    }

    public function test_ciphertext_from_another_tenant_cannot_support_admin_approval(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $otherCompany = $this->company($other);
        $document = $this->upload($owner, $company, "%PDF-1.4\nORIGINAL-PRIVATE");
        $otherDocument = $this->upload($other, $otherCompany, "%PDF-1.4\nOTHER-TENANT-PRIVATE");

        $this->actingAs($owner, 'web')
            ->postJson('/api/vendor-ops/companies/'.$company->id.'/submit')->assertOk();
        Storage::disk('local')->put(
            $document->getRawOriginal('storage_path'),
            Storage::disk('local')->get($otherDocument->getRawOriginal('storage_path'))
        );

        $this->review($admin, $company, 'approved')->assertStatus(409)
            ->assertDontSee('OTHER-TENANT-PRIVATE');
        $this->assertSame('submitted', $company->fresh()->status);
        $this->assertSame(0, VendorCompanyReview::where('company_id', $company->id)->count());
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'vendor_ops.company.approved', 'subject' => (string) $company->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'vendor_ops.document.integrity_failed',
            'subject' => (string) $document->id, 'actor_id' => $admin->id,
        ]);

        // Correction is the established path for replacing invalid evidence.
        $this->review($admin, $company, 'changes_requested')->assertOk();
        $newDocument = $this->upload($owner, $company, "%PDF-1.4\nREPLACEMENT-VALID");
        $this->actingAs($owner, 'web')
            ->postJson('/api/vendor-ops/companies/'.$company->id.'/submit')->assertOk();
        $this->review($admin, $company, 'approved')->assertOk()
            ->assertJsonPath('status', 'approved');
        $this->assertNotSame($document->id, $newDocument->id);
    }

    public function test_missing_encrypted_file_blocks_review_without_success_audit(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $document = $this->upload($owner, $company, "%PDF-1.4\nLICENSE-PRIVATE");
        $this->actingAs($owner, 'web')
            ->postJson('/api/vendor-ops/companies/'.$company->id.'/submit')->assertOk();
        Storage::disk('local')->delete($document->getRawOriginal('storage_path'));

        $this->review($admin, $company, 'approved')->assertStatus(409);
        $this->assertSame('submitted', $company->fresh()->status);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'vendor_ops.company.approved', 'subject' => (string) $company->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id, 'action' => 'vendor_ops.document.integrity_failed',
            'subject' => (string) $document->id,
        ]);
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'vendor_ops.document.downloaded')->count());
    }
}
