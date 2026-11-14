<?php

namespace Tests\Feature;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use App\Security\AdminTotp;
use App\Services\Privacy\AccountDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUnpaidCreditInvoice;
use Tests\TestCase;

class PrivacyAccessExportTest extends TestCase
{
    use CreatesUnpaidCreditInvoice { scenario as chargingScenario; }
    use RefreshDatabase;

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create();
        $case = AccountPrivacyRequest::create(['user_id' => $owner->id, 'kind' => 'access', 'status' => 'reviewing', 'request_details_encrypted' => Crypt::encryptString('Please provide my personal account data.')]);
        foreach ([$owner, $other] as $user) {
            DB::table('vehicles')->insert(['user_id' => $user->id, 'name' => $user->id === $owner->id ? 'Own private vehicle' : 'OTHER PRIVATE VEHICLE', 'connector_type' => 'CCS2', 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$owner, $admin, $other, $case];
    }

    public function test_separate_review_prepares_one_encrypted_scoped_copy_and_only_reauthenticated_owner_can_read(): void
    {
        [$owner, $admin, $other, $case] = $this->scenario();
        $prepare = '/api/admin/privacy-requests/'.$case->id.'/access-copy';
        $read = '/api/account/privacy-requests/'.$case->id.'/access-copy';
        $body = ['password' => 'password', 'request_key' => 'private-export-key-1'];
        $this->actingAs($owner)->postJson($prepare, $body)->assertForbidden();
        $this->actingAs($admin)->postJson($prepare, $body)->assertCreated()->assertJsonPath('copy.status', 'available');
        $this->postJson($prepare, $body)->assertOk();
        $this->postJson($prepare, array_replace($body, ['request_key' => 'different-request-key']))->assertConflict();
        $stored = DB::table('account_privacy_exports')->first();
        $this->assertStringNotContainsString($owner->email, $stored->payload_encrypted);
        $this->assertSame('closed_review', $case->fresh()->status);
        $this->assertDatabaseCount('account_privacy_exports', 1);
        $this->assertDatabaseCount('account_privacy_request_reviews', 1);
        $this->postJson($read, ['password' => 'password'])->assertNotFound();
        $this->actingAs($other)->postJson($read, ['password' => 'password'])->assertNotFound();
        $this->actingAs($owner)->postJson($read, ['password' => 'wrong'])->assertUnprocessable();
        $copy = $this->postJson($read, ['password' => 'password'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('owner_id', $owner->id)->assertJsonPath('request_id', $case->id);
        $this->assertCount(1, $copy->json('sections.vehicles'));
        $this->assertStringNotContainsString('OTHER PRIVATE VEHICLE', $copy->getContent());
        foreach (['password', 'auth_epoch', 'remember_token', 'review_note_encrypted', 'secret', 'request_key'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'":', $copy->getContent());
        }
        $this->getJson('/api/account/privacy-requests')->assertOk()->assertJsonPath('data.0.copy.status', 'available')->assertJsonMissingPath('data.0.copy.payload_encrypted');
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_discard_expiry_integrity_and_review_state_fail_closed_without_reopening_a_copy(): void
    {
        [$owner, $admin, $other, $case] = $this->scenario();
        $path = '/api/account/privacy-requests/'.$case->id.'/access-copy';
        $this->actingAs($admin)->postJson('/api/admin/privacy-requests/'.$case->id.'/access-copy', ['password' => 'password', 'request_key' => 'privacy-discard-key'])->assertCreated();
        DB::table('account_privacy_exports')->update(['sha256' => str_repeat('a', 64)]);
        $this->actingAs($owner)->postJson($path, ['password' => 'password'])->assertConflict();
        $this->actingAs($other)->postJson($path.'/revoke')->assertNotFound();
        $this->actingAs($owner)->postJson($path.'/revoke')->assertOk()->assertJsonPath('discarded', true);
        $this->postJson($path.'/revoke')->assertOk();
        $this->postJson($path, ['password' => 'password'])->assertGone();
        $this->assertNull(DB::table('account_privacy_exports')->value('payload_encrypted'));
        $this->getJson('/api/account/privacy-requests')->assertJsonPath('data.0.copy.status', 'revoked');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.copy.discarded')->count());
    }

    public function test_large_requests_never_silently_truncate_and_expired_copies_are_purged(): void
    {
        [$owner, $admin, , $case] = $this->scenario();
        $path = '/api/admin/privacy-requests/'.$case->id.'/access-copy';
        $body = ['password' => 'password', 'request_key' => 'privacy-expiry-key'];
        $case->update(['kind' => 'erasure']);
        $this->actingAs($admin)->postJson($path, $body)->assertConflict();
        $case->update(['kind' => 'access', 'status' => 'pending']);
        $this->postJson($path, $body)->assertConflict();
        $case->update(['status' => 'reviewing']);
        $this->postJson($path, $body)->assertCreated();
        $this->travel(25)->hours();
        $this->actingAs($owner)->postJson('/api/account/privacy-requests/'.$case->id.'/access-copy', ['password' => 'password'])->assertGone();
        $this->assertSame(1, app(AccountDataExport::class)->purge());
        $this->assertSame(0, app(AccountDataExport::class)->purge());
        $this->assertNull(DB::table('account_privacy_exports')->value('payload_encrypted'));
        $this->assertNotNull(DB::table('account_privacy_exports')->value('sha256'));
        $batch = array_fill(0, 2500, ['user_id' => $owner->id, 'name' => 'Own garage record', 'connector_type' => 'CCS2', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vehicles')->insert($batch);
        $new = AccountPrivacyRequest::create(['user_id' => $owner->id, 'kind' => 'access', 'status' => 'reviewing', 'request_details_encrypted' => Crypt::encryptString('Please provide a new complete copy.')]);
        $this->actingAs($admin)->postJson('/api/admin/privacy-requests/'.$new->id.'/access-copy', $body)->assertConflict();
        $this->assertSame('reviewing', $new->fresh()->status);
        $this->assertDatabaseCount('account_privacy_exports', 1);
    }

    public function test_enabled_authenticator_is_required_and_its_code_is_single_use(): void
    {
        [$owner, $admin, , $case] = $this->scenario();
        $this->actingAs($admin)->postJson('/api/admin/privacy-requests/'.$case->id.'/access-copy', ['password' => 'password', 'request_key' => 'privacy-second-factor'])->assertCreated();
        $secret = AdminTotp::generateSecret();
        DB::table('account_second_factors')->insert(['user_id' => $owner->id, 'secret' => Crypt::encryptString($secret), 'recovery_hashes' => Crypt::encryptString('[]'), 'created_at' => now(), 'updated_at' => now()]);
        $path = '/api/account/privacy-requests/'.$case->id.'/access-copy';
        $this->actingAs($owner)->postJson($path, ['password' => 'password'])->assertUnprocessable();
        $code = AdminTotp::codeAtStep($secret, intdiv(now()->timestamp, 30));
        $this->postJson($path, ['password' => 'password', 'second_factor_code' => $code])->assertOk();
        $this->postJson($path, ['password' => 'password', 'second_factor_code' => $code])->assertUnprocessable();
        $this->assertSame(1, DB::table('audit_events')->where('action', 'privacy.copy.viewed')->count());
    }

    public function test_copy_preserves_canonical_three_decimal_invoice_lineage_without_ledger_mutation(): void
    {
        [, $owner, , $invoice] = $this->chargingScenario('KWD', 3, '0.251', 251, 500);
        $admin = User::factory()->create(['role' => 'admin']);
        $case = AccountPrivacyRequest::create(['user_id' => $owner->id, 'kind' => 'access', 'status' => 'reviewing', 'request_details_encrypted' => Crypt::encryptString('Please include my canonical financial references.')]);
        $journals = DB::table('finance_journals')->count();
        $this->actingAs($admin)->postJson('/api/admin/privacy-requests/'.$case->id.'/access-copy', ['password' => 'password', 'request_key' => 'privacy-money-precision'])->assertCreated();
        $this->actingAs($owner)->postJson('/api/account/privacy-requests/'.$case->id.'/access-copy', ['password' => 'password'])->assertOk()
            ->assertJsonPath('sections.invoices.0.number', $invoice->number)
            ->assertJsonPath('sections.invoices.0.currency', 'KWD')
            ->assertJsonPath('sections.invoices.0.minor_units', 3)
            ->assertJsonPath('sections.invoices.0.total_minor', (string) $invoice->total_minor);
        $this->assertDatabaseCount('finance_journals', $journals);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_expiry_purge_is_bounded_and_rollback_cannot_delete_delivery_receipts(): void
    {
        [$owner, $admin, , $case] = $this->scenario();
        $this->actingAs($admin)->postJson('/api/admin/privacy-requests/'.$case->id.'/access-copy', ['password' => 'password', 'request_key' => 'bounded-expiry-proof'])->assertCreated();
        $original = DB::table('account_privacy_exports')->first();
        $cases = [];
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $id = $case->id + $i + 1;
            $cases[] = ['id' => $id, 'user_id' => $owner->id, 'kind' => 'access', 'status' => 'closed_review', 'request_details_encrypted' => Crypt::encryptString('Synthetic bounded expiry proof'), 'created_at' => now(), 'updated_at' => now()];
            $row = (array) $original;
            unset($row['id']);
            $row['privacy_request_id'] = $id;
            $rows[] = $row;
        }
        foreach (array_chunk($cases, 100) as $batch) {
            DB::table('account_privacy_requests')->insert($batch);
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('account_privacy_exports')->insert($batch);
        }
        $this->travel(25)->hours();
        $exports = app(AccountDataExport::class);
        $this->assertSame(1000, $exports->purge());
        $this->assertSame(1, DB::table('account_privacy_exports')->whereNotNull('payload_encrypted')->count());
        $this->assertSame(1, $exports->purge());
        $this->assertSame(0, $exports->purge());
        $this->assertDatabaseCount('account_privacy_exports', 1001);
        $this->expectException(\RuntimeException::class);
        $migration = require database_path('migrations/2026_11_26_000001_create_privacy_access_exports.php');
        $migration->down();
    }
}
