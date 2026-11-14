<?php

namespace Tests\Feature;

use App\Http\Controllers\VendorAgreementController;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VendorAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['web', 'auth'])->prefix('agreement-test')->group(base_path('routes/vendor_ops.php'));
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'PRIVATE COMPANY SNAPSHOT', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'draft']);

        return [$admin, $owner, $company, '/agreement-test/companies/'.$company->id.'/agreement'];
    }

    private function text(int $revision = 0): array
    {
        return ['password' => 'password', 'expected_revision' => $revision, 'title_en' => 'Synthetic vendor agreement', 'title_ar' => 'اتفاقية شركة تجريبية', 'body_en' => 'Synthetic agreement for software tests only. No production legal acceptance is represented.', 'body_ar' => 'اتفاقية تجريبية لاختبارات البرمجيات فقط ولا تمثل قبولاً قانونياً للإنتاج.', 'publication_reference' => 'SYNTHETIC-REVIEW-ONLY', 'approved_for_publication' => true];
    }

    private function publish(User $admin, string $base, int $revision = 0): array
    {
        return $this->actingAs($admin)->postJson($base.'/publish', $this->text($revision))->assertCreated()->json();
    }

    private function consent(array $published): array
    {
        return ['version_id' => $published['version_id'], 'sha256' => $published['sha256'], 'accepted' => true];
    }

    public function test_explicit_owner_acceptance_is_immutable_recoverable_and_has_no_operational_side_effect(): void
    {
        [$admin, $owner, $company, $base] = $this->fixture();
        $published = $this->publish($admin, $base);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('can_accept', true)->assertJsonPath('can_publish', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($base.'/accept', array_replace($this->consent($published), ['accepted' => false]))->assertUnprocessable();
        $receipt = $this->postJson($base.'/accept', $this->consent($published))->assertCreated()->assertJsonPath('charging_authority_granted', false)->assertJsonPath('payment_authority_granted', false)->json();
        $this->postJson($base.'/accept', $this->consent($published))->assertOk()->assertJsonPath('id', $receipt['id']);
        $this->getJson($base)->assertOk()->assertJsonPath('can_accept', false)->assertJsonPath('history.total', 1);
        $this->getJson($base.'/receipts/'.$receipt['id'])->assertOk()->assertJsonPath('version.sha256', $published['sha256']);
        $stored = DB::table('vendor_agreement_acceptances')->first();
        $this->assertStringNotContainsString($company->legal_name, $stored->company_snapshot_encrypted);
        $this->assertSame($company->legal_name, json_decode(Crypt::decryptString($stored->company_snapshot_encrypted), true)['legal_name']);
        $this->assertSame('draft', $company->fresh()->status);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('vendor_agreement_acceptances', 1);
        $this->expectException(QueryException::class);
        DB::table('vendor_agreement_acceptances')->where('id', $receipt['id'])->delete();
    }

    public function test_updated_agreement_requires_fresh_explicit_consent_and_preserves_old_receipts(): void
    {
        [$admin, $owner, , $base] = $this->fixture();
        $first = $this->publish($admin, $base);
        $receipt = $this->actingAs($owner)->postJson($base.'/accept', $this->consent($first))->assertCreated()->json();
        $second = $this->publish($admin, $base, 1);
        $this->actingAs($owner)->postJson($base.'/accept', $this->consent($first))->assertConflict();
        $this->getJson($base.'/receipts/'.$receipt['id'])->assertOk()->assertJsonPath('version.revision', 1);
        $this->getJson($base)->assertOk()->assertJsonPath('can_accept', true)->assertJsonPath('acceptance', null);
        $this->postJson($base.'/accept', array_replace($this->consent($second), ['sha256' => $first['sha256']]))->assertConflict();
        $this->postJson($base.'/accept', $this->consent($second))->assertCreated();
        $this->assertDatabaseCount('vendor_agreement_acceptances', 2);
    }

    public function test_admin_publication_requires_password_and_revision_and_recovers_exact_lost_response(): void
    {
        [$admin, $owner, , $base] = $this->fixture();
        $this->actingAs($owner)->postJson($base.'/publish', $this->text())->assertForbidden();
        $this->actingAs($admin)->postJson($base.'/publish', array_replace($this->text(), ['password' => 'wrong']))->assertUnprocessable();
        $first = $this->publish($admin, $base);
        $this->postJson($base.'/publish', $this->text())->assertOk()->assertJsonPath('version_id', $first['version_id']);
        $this->postJson($base.'/publish', array_replace($this->text(), ['body_en' => 'A different competing publication using an outdated revision.']))->assertConflict();
        $this->postJson($base.'/accept', $this->consent($first))->assertForbidden();
        $this->assertDatabaseCount('vendor_agreement_versions', 1);
        $this->expectException(QueryException::class);
        DB::table('vendor_agreement_versions')->where('id', $first['version_id'])->update(['body_en' => 'rewritten']);
    }

    public function test_other_company_staff_and_foreign_receipts_cannot_cross_owner_scope(): void
    {
        [$admin, $owner, $company, $base] = $this->fixture();
        $first = $this->publish($admin, $base);
        $receipt = $this->actingAs($owner)->postJson($base.'/accept', $this->consent($first))->assertCreated()->json();
        $other = User::factory()->create(['role' => 'vendor']);
        DB::table('vendor_company_staff')->insert(['company_id' => $company->id, 'user_id' => $other->id, 'status' => 'active', 'permissions' => json_encode(['company.read']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($other)->getJson($base)->assertNotFound();
        $this->postJson($base.'/accept', $this->consent($first))->assertNotFound();
        $this->getJson($base.'/receipts/'.$receipt['id'])->assertNotFound();
    }

    public function test_stale_authenticated_snapshot_cannot_accept_after_global_revocation(): void
    {
        [$admin, $owner, $company, $base] = $this->fixture();
        $first = $this->publish($admin, $base);
        $stale = clone $owner;
        DB::table('users')->where('id', $owner->id)->increment('auth_epoch');
        $r = Request::create('/agreement-test', 'POST', $this->consent($first));
        $r->setUserResolver(fn () => $stale);
        try {
            app(VendorAgreementController::class)->accept($r, $company);
            $this->fail('A stale authenticated snapshot recorded company consent.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('vendor_agreement_acceptances', 0);
    }

    public function test_real_native_owner_token_can_accept_but_admin_token_cannot_publish(): void
    {
        [$admin, $owner, $company, $base] = $this->fixture();
        $first = $this->publish($admin, $base);
        $this->app['auth']->forgetGuards();
        $path = '/api/v1/vendor-ops/companies/'.$company->id.'/agreement';
        $this->withToken($owner->createToken('native-agreement')->plainTextToken)->getJson($path)->assertOk();
        $this->postJson($path.'/accept', $this->consent($first))->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('forbidden-admin-native')->plainTextToken)->postJson($path.'/publish', $this->text(1))->assertForbidden();
    }

    public function test_admin_web_publication_does_not_bypass_mfa_when_enforced(): void
    {
        [$admin, , , $base] = $this->fixture();
        config(['admin_security.enforce_in_local_tests' => true]);
        $this->actingAs($admin)->postJson($base.'/publish', $this->text())->assertForbidden();
        $this->assertDatabaseCount('vendor_agreement_versions', 0);
    }

    public function test_withdrawal_fences_new_acceptance_and_retains_receipts_before_republication(): void
    {
        [$admin, $owner, , $base] = $this->fixture();
        $first = $this->publish($admin, $base);
        $receipt = $this->actingAs($owner)->postJson($base.'/accept', $this->consent($first))->assertCreated()->json();
        $values = ['password' => 'password', 'expected_revision' => 1, 'version_id' => $first['version_id'], 'confirmed' => true];
        $this->postJson($base.'/withdraw', $values)->assertForbidden();
        $this->actingAs($admin)->postJson($base.'/withdraw', $values)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson($base.'/withdraw', $values)->assertOk();
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('version', null)->assertJsonPath('can_accept', false)->assertJsonPath('history.total', 1);
        $this->postJson($base.'/accept', $this->consent($first))->assertConflict();
        $this->getJson($base.'/receipts/'.$receipt['id'])->assertOk()->assertJsonPath('version.revision', 1);
        $third = $this->publish($admin, $base, 2);
        $this->postJson($base.'/withdraw', $values)->assertConflict();
        $this->actingAs($owner)->postJson($base.'/accept', $this->consent($third))->assertCreated();
        $this->assertDatabaseCount('vendor_agreement_versions', 2);
        $this->assertDatabaseCount('vendor_agreement_acceptances', 2);
    }

    public function test_company_country_change_requires_its_current_country_document(): void
    {
        [$admin, $owner, $company, $base] = $this->fixture();
        $first = $this->publish($admin, $base);
        $company->update(['country' => 'SA']);
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('version', null)->assertJsonPath('can_accept', false);
        $this->postJson($base.'/accept', $this->consent($first))->assertConflict();
        $this->assertDatabaseCount('vendor_agreement_acceptances', 0);
    }

    public function test_rollback_cannot_erase_published_agreement_evidence(): void
    {
        [$admin, , , $base] = $this->fixture();
        $this->publish($admin, $base);
        $migration = require database_path('migrations/2026_11_29_000001_create_vendor_agreement_records.php');
        try {
            $migration->down();
            $this->fail('Published agreement evidence was removed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preserve published agreement', $e->getMessage());
        }
        $this->assertDatabaseCount('vendor_agreement_versions', 1);
    }
}
