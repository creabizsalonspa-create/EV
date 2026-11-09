<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorKycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VendorKycIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! collect(Route::getRoutes())->contains(fn ($route) =>             $route->uri() === 'api/vendor-ops/companies' && in_array('GET', $route->methods()))) {
            Route::middleware(['web', 'auth'])->prefix('api/vendor-ops')
                ->group(base_path('routes/vendor_ops.php'));
        }
        Storage::fake('local');
    }

    private function company(User $owner): VendorCompany
    {
        return VendorCompany::create([
            'owner_id' => $owner->id,
            'legal_name' => 'Synthetic KYC operator '.$owner->id,
            'country' => 'AE',
            'trade_license_number' => 'PRIVATE-TEST',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'draft',
        ]);
    }

    private function upload(User $owner, VendorCompany $company, string $bytes): VendorKycDocument
    {
        $this->actingAs($owner, 'web')->post(
            '/api/vendor-ops/companies/'.$company->id.'/documents',
            [
                'kind' => 'identity',
                'document' => UploadedFile::fake()->createWithContent('evidence.pdf', $bytes),
            ],
            ['Accept' => 'application/json']
        )->assertCreated();

        return VendorKycDocument::where('company_id', $company->id)->firstOrFail();
    }

    public function test_private_upload_fingerprint_is_not_disclosed_in_kyc_metadata_responses(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->company($owner);
        $bytes = "%PDF-1.4\nSYNTHETIC-PRIVATE-EVIDENCE-FINGERPRINT";
        $digest = hash('sha256', $bytes);
        $base = '/api/vendor-ops/companies/'.$company->id;

        $this->actingAs($owner, 'web')->post(
            $base.'/documents',
            [
                'kind' => 'identity',
                'document' => UploadedFile::fake()->createWithContent('fingerprint.pdf', $bytes),
            ],
            ['Accept' => 'application/json']
        )->assertCreated()
            ->assertJsonMissingPath('sha256')
            ->assertJsonMissingPath('storage_path')
            ->assertDontSee($digest)
            ->assertHeader('Cache-Control', 'no-store, private');

        $document = VendorKycDocument::where('company_id', $company->id)->firstOrFail();
        $this->assertSame($digest, $document->sha256);
        $this->assertSame(strlen($bytes), (int) $document->size_bytes);

        foreach ([$owner, $admin] as $viewer) {
            $this->actingAs($viewer, 'web')->getJson($base.'/documents')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonMissingPath('data.0.sha256')
                ->assertJsonMissingPath('data.0.storage_path')
                ->assertDontSee($digest)
                ->assertJsonPath('data.0.size_bytes', strlen($bytes));
        }

        $this->actingAs($owner, 'web')
            ->get($base.'/documents/'.$document->id)
            ->assertOk()->assertContent($bytes);
    }

    public function test_admin_private_kyc_metadata_and_review_reads_are_audited_without_evidence_details(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'vendor']);
        $company = $this->company($owner);
        $document = $this->upload($owner, $company, "%PDF-1.4\nPRIVATE-AUDIT-EVIDENCE");
        $base = '/api/vendor-ops/companies/'.$company->id;

        $this->actingAs($admin, 'web')->getJson($base.'/documents')
            ->assertOk()->assertJsonMissingPath('data.0.sha256')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($base.'/reviews')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        foreach ([
            'vendor_ops.document.metadata_viewed',
            'vendor_ops.review.history_viewed',
        ] as $action) {
            $this->assertDatabaseHas('audit_events', [
                'actor_id' => $admin->id,
                'action' => $action,
                'subject' => (string) $company->id,
            ]);
        }
        $this->assertSame(1, DB::table('audit_events')
            ->where('actor_id', $admin->id)
            ->where('action', 'vendor_ops.document.metadata_viewed')->count());
        $this->assertStringNotContainsString($document->sha256,
            DB::table('audit_events')->get()->toJson());

        $this->actingAs($other, 'web')->getJson($base.'/documents')->assertNotFound();
        $this->getJson($base.'/reviews')->assertNotFound();
        $this->assertSame(2, DB::table('audit_events')
            ->where('actor_id', $admin->id)
            ->whereIn('action', [
                'vendor_ops.document.metadata_viewed',
                'vendor_ops.review.history_viewed',
            ])->count());
    }

    public function test_owner_private_kyc_metadata_and_review_history_are_never_shared_cacheable(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $company = $this->company($owner);
        $this->upload($owner, $company, "%PDF-1.4\nPRIVATE-EVIDENCE");

        $base = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($owner, 'web')
            ->getJson($base)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($base.'/documents')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.storage_path');
        $this->getJson($base.'/reviews')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(0, 'data');

        $this->actingAs($other, 'web')->getJson($base.'/documents')->assertNotFound();
        $this->getJson($base.'/reviews')->assertNotFound();
    }

    public function test_valid_ciphertext_from_another_company_must_not_be_released_as_target_evidence(): void
    {
        $ownerA = User::factory()->create(['role' => 'vendor']);
        $ownerB = User::factory()->create(['role' => 'vendor']);
        $companyA = $this->company($ownerA);
        $companyB = $this->company($ownerB);
        $first = "%PDF-1.4\nPRIVATE-FIRST-IDENTITY";
        $second = "%PDF-1.4\nPRIVATE-SECOND-IDENTITY";
        $docA = $this->upload($ownerA, $companyA, $first);
        $docB = $this->upload($ownerB, $companyB, $second);

        // Both ciphertexts are valid Laravel-encrypted evidence. A storage
        // path mix-up must never disclose company B's bytes to company A.
        Storage::disk('local')->put(
            $docA->getRawOriginal('storage_path'),
            Storage::disk('local')->get($docB->getRawOriginal('storage_path'))
        );

        $urlA = '/api/vendor-ops/companies/'.$companyA->id.'/documents/'.$docA->id;
        $this->actingAs($ownerA, 'web')
            ->get($urlA)->assertStatus(409)->assertDontSee('PRIVATE-SECOND-IDENTITY');
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'vendor_ops.document.downloaded',
            'subject' => (string) $docA->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'vendor_ops.document.integrity_failed',
            'subject' => (string) $docA->id,
        ]);

        $this->actingAs($ownerB, 'web')
            ->get('/api/vendor-ops/companies/'.$companyB->id.'/documents/'.$docB->id)
            ->assertOk()->assertContent($second);
    }

    public function test_missing_encrypted_file_fails_closed_without_successful_download_audit(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = $this->company($owner);
        $doc = $this->upload($owner, $company, "%PDF-1.4\nKYC-PRIVATE");
        Storage::disk('local')->delete($doc->getRawOriginal('storage_path'));

        $this->actingAs($owner, 'web')
            ->get('/api/vendor-ops/companies/'.$company->id.'/documents/'.$doc->id)
            ->assertStatus(409);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'vendor_ops.document.downloaded',
            'subject' => (string) $doc->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'vendor_ops.document.integrity_failed',
            'subject' => (string) $doc->id,
        ]);
    }
}
