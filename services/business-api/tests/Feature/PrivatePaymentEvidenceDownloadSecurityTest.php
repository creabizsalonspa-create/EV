<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\Payments\GatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Real Laravel HTTP security envelope for encrypted dispute evidence.
 * A synthetic PDF containing HTML must download, never render as a page.
 */
class PrivatePaymentEvidenceDownloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_dispute_evidence_download_preserves_sandbox_and_tenant_boundaries(): void
    {
        Http::preventStrayRequests();
        Storage::fake('local');
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        $paymentCount = DB::table('finance_payments')->count();
        $refundCount = DB::table('finance_refunds')->count();
        $case = app(GatewayService::class)->createCase(
            $f['invoice'], $f['customer']->id, 'dispute', 100,
            'Synthetic source security review', 'qa-private-download',
        );

        $prefix = '/api/v1/commercial/cases/'.$case->id.'/evidence';
        $bytes = "%PDF-1.4\n".'<script>window.location="https://example.invalid/"</script>';
        $uploaded = $this->actingAs($f['customer'])->post($prefix, [
            'kind' => 'service_record',
            'consent' => true,
            'idempotency_key' => 'synthetic-pdf',
            'document' => UploadedFile::fake()->createWithContent('proof.pdf', $bytes),
        ], ['Accept' => 'application/json'])->assertCreated()->json();

        $path = $prefix.'/'.$uploaded['id'];
        $this->actingAs(User::factory()->create())->getJson($path)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($path)->assertNotFound();
        $this->actingAs($f['customer']);

        $response = $this->get($path)->assertOk();
        $this->assertSame($bytes, $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('case-evidence-'.$uploaded['id'].'.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame("sandbox; default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('gateway_case_evidence', 1);
        $this->assertDatabaseCount('finance_payments', $paymentCount);
        $this->assertDatabaseCount('finance_refunds', $refundCount);
        Http::assertNothingSent();
    }

    public function test_unsigned_anonymous_request_cannot_enumerate_private_evidence(): void
    {
        Http::preventStrayRequests();

        foreach ([
            '/api/v1/commercial/cases/1/evidence',
            '/api/v1/commercial/cases/1/evidence/1',
        ] as $path) {
            $response = $this->getJson($path)->assertUnauthorized();
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }

        $this->assertDatabaseCount('gateway_case_evidence', 0);
        Http::assertNothingSent();
    }
}
