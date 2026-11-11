<?php

namespace Tests\Feature;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use App\Services\Privacy\LegacyPrivacyIntakeImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyPrivacyIntakeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_encrypted_intake_and_review_are_preserved_once_without_dropping_archive(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create(['role' => 'admin']);
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('request_type');
            $table->text('reason')->nullable();
            $table->string('idempotency_key');
            $table->string('status');
            $table->string('resolution')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
        $row = ['user_id' => $owner->id, 'request_type' => 'account_deletion',
            'reason' => Crypt::encryptString('PRIVATE: legacy deletion review request.'),
            'idempotency_key' => 'preserved-legacy-request', 'status' => 'closed', 'resolution' => 'referred',
            'decision_note' => Crypt::encryptString('PRIVATE: independent compliance referral.'),
            'reviewer_id' => $reviewer->id, 'reviewed_at' => now(), 'withdrawn_at' => null,
            'created_at' => now(), 'updated_at' => now()];
        DB::table('privacy_requests')->insert($row);
        DB::table('privacy_requests')->insert(array_merge($row, ['user_id' => null, 'idempotency_key' => 'retained-orphan-review']));
        $importer = app(LegacyPrivacyIntakeImporter::class);
        $this->assertSame(1, $importer->import());
        $this->assertSame(0, $importer->import());
        $case = AccountPrivacyRequest::firstOrFail();
        $this->assertSame('erasure', $case->kind);
        $this->assertSame('closed_review', $case->status);
        $this->assertSame('referred', $case->legacy_resolution);
        $this->assertSame($reviewer->id, $case->reviewed_by);
        $this->assertSame('PRIVATE: legacy deletion review request.', Crypt::decryptString($case->request_details_encrypted));
        $this->assertStringNotContainsString('PRIVATE', $case->request_details_encrypted);
        $this->assertDatabaseCount('privacy_requests', 2);
        $this->assertDatabaseCount('account_privacy_request_reviews', 1);
        $this->actingAs($owner)->getJson('/api/account/privacy-requests')->assertOk()
            ->assertJsonPath('data.0.id', $case->id)->assertJsonMissingPath('data.0.review_note_encrypted');
    }

    public function test_fresh_install_does_not_create_a_duplicate_privacy_database(): void
    {
        $this->assertFalse(Schema::hasTable('privacy_requests'));
        $this->assertSame(0, app(LegacyPrivacyIntakeImporter::class)->import());
    }
}
