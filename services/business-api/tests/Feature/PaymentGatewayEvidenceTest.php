<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Commercial\Payments\GatewayService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentGatewayEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $f = (require base_path('tests/Fixtures/vendor-payout.php'))();
        $case = app(GatewayService::class)->createCase($f['invoice'], $f['customer']->id, 'dispute', 100, 'Synthetic service dispute', 'evidence-case');

        return $f + ['case' => $case];
    }

    private function path(array $f): string
    {
        return '/api/commercial/cases/'.$f['case']->id.'/evidence';
    }

    private function body(string $key = 'private-upload', string $text = 'Synthetic private service record'): array
    {
        return ['kind' => 'service_record', 'consent' => true, 'idempotency_key' => $key, 'document' => UploadedFile::fake()->createWithContent('private-name.pdf', "%PDF-1.4\n".$text)];
    }

    public function test_private_evidence_is_encrypted_owner_scoped_immutable_replayable_and_never_provider_submission(): void
    {
        $f = $this->fixture();
        $journals = DB::table('finance_journals')->count();
        $row = $this->actingAs($f['customer'])->post($this->path($f), $this->body(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('provider_submitted', false)->assertJsonPath('evidence_status', 'PRIVATE_REVIEW_ONLY')->assertJsonMissingPath('storage_path')->assertJsonMissingPath('request_key')->json();
        $this->post($this->path($f), $this->body(), ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('id', $row['id']);
        $stored = DB::table('gateway_case_evidence')->find($row['id']);
        $cipher = Storage::disk('local')->get($stored->storage_path);
        $this->assertStringNotContainsString('Synthetic private service record', $cipher);
        $this->assertSame("%PDF-1.4\nSynthetic private service record", Crypt::decryptString($cipher));
        $download = $this->actingAs($f['vendor'])->get($this->path($f).'/'.$row['id'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($row['sha256'], hash('sha256', $download->getContent()));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->getJson($this->path($f))->assertOk()->assertJsonPath('total', 1);
        $this->assertSame($journals, DB::table('finance_journals')->count());
        $this->assertDatabaseCount('gateway_case_evidence', 1);
        $this->assertDatabaseCount('gateway_cases', 1);
    }

    public function test_foreign_users_and_other_case_paths_cannot_read_or_upload_private_evidence(): void
    {
        $f = $this->fixture();
        $row = $this->actingAs($f['customer'])->post($this->path($f), $this->body(), ['Accept' => 'application/json'])->assertCreated()->json();
        foreach ([User::factory()->create(), User::factory()->create(['role' => 'vendor'])] as $foreign) {
            $this->actingAs($foreign)->getJson($this->path($f))->assertNotFound();
            $this->getJson($this->path($f).'/'.$row['id'])->assertNotFound();
            $this->post($this->path($f), $this->body('foreign'), ['Accept' => 'application/json'])->assertNotFound();
        }
        $this->actingAs($f['maker'])->getJson($this->path($f).'/'.$row['id'])->assertOk();
        $this->getJson('/api/commercial/cases/999999/evidence/'.$row['id'])->assertNotFound();
    }

    public function test_changed_payload_conflicts_and_resolved_case_retains_replay_but_blocks_new_evidence(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer'])->post($this->path($f), $this->body(), ['Accept' => 'application/json'])->assertCreated();
        $this->post($this->path($f), $this->body(text: 'Changed bytes'), ['Accept' => 'application/json'])->assertConflict();
        app(GatewayService::class)->resolveCase($f['case']->id, $f['maker']->id, 'rejected', 'Synthetic case closed');
        $this->post($this->path($f), $this->body(), ['Accept' => 'application/json'])->assertCreated();
        $this->post($this->path($f), $this->body('new-key'), ['Accept' => 'application/json'])->assertConflict();
        $this->getJson($this->path($f))->assertOk()->assertJsonPath('can_upload', false);
        $this->assertDatabaseCount('gateway_case_evidence', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_invalid_consent_type_size_and_anonymous_access_fail_before_private_storage(): void
    {
        $f = $this->fixture();
        $this->getJson($this->path($f))->assertUnauthorized();
        $this->actingAs($f['customer']);
        $this->post($this->path($f), array_replace($this->body(), ['consent' => false]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($this->path($f), array_replace($this->body(), ['document' => UploadedFile::fake()->createWithContent('evil.html', '<script>unsafe</script>')]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($this->path($f), array_replace($this->body(), ['document' => UploadedFile::fake()->create('large.pdf', 4097, 'application/pdf')]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('gateway_case_evidence', 0);
    }

    public function test_private_download_rejects_ciphertext_and_content_integrity_tampering(): void
    {
        $f = $this->fixture();
        $row = $this->actingAs($f['customer'])->post($this->path($f), $this->body(), ['Accept' => 'application/json'])->assertCreated()->json();
        $stored = DB::table('gateway_case_evidence')->find($row['id']);
        Storage::disk('local')->put($stored->storage_path, Crypt::encryptString('Other plaintext'));
        $this->getJson($this->path($f).'/'.$row['id'])->assertStatus(503);
        Storage::disk('local')->put($stored->storage_path, 'invalid ciphertext');
        $this->getJson($this->path($f).'/'.$row['id'])->assertStatus(503);
    }

    public function test_evidence_metadata_cannot_be_rewritten_at_the_database_layer(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer'])->post($this->path($f), $this->body(), ['Accept' => 'application/json'])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('gateway_case_evidence')->update(['sha256' => str_repeat('0', 64)]);
    }
}
