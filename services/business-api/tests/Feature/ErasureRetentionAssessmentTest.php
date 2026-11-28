<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErasureRetentionAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_is_case_scoped_private_read_only_and_never_authorizes_erasure(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $other = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $case = $this->actingAs($owner)->postJson('/api/account/privacy-requests', [
            'kind' => 'erasure', 'details' => 'Review my private retained account data.',
        ])->assertCreated()->json('id');
        VendorCompany::create([
            'owner_id' => $owner->id, 'legal_name' => 'PRIVATE BUSINESS', 'country' => 'AE',
            'status' => 'approved', 'trade_license_number' => 'PRIVATE-TRADE-LICENSE',
            'license_expires_on' => now()->addYear()->toDateString(),
        ]);
        $owner->createToken('retention-review');
        $url = '/api/admin/privacy-requests/'.$case.'/erasure-assessment';
        $this->getJson($url)->assertForbidden();
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $response = $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('request_id', $case)->assertJsonPath('owner_id', $owner->id)
            ->assertJsonPath('case_status', 'pending')->assertJsonPath('execution_authorized', false)
            ->assertJsonPath('automated_erasure_performed', false)->assertJsonPath('legal_retention_verified', false)
            ->assertJsonCount(7, 'dependencies');
        $states = array_column($response->json('dependencies'), 'state', 'key');
        $this->assertSame('present', $states['business_relationships']);
        $this->assertSame('present', $states['privacy_records']);
        $this->assertSame('present', $states['active_credentials']);
        $this->assertSame('not_observed', $states['financial_records']);
        $this->assertSame('unknown', $states['external_and_legal_retention']);
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'account_status' => 'active']);
        $this->assertDatabaseHas('account_privacy_requests', ['id' => $case, 'status' => 'pending']);
        $this->assertDatabaseCount('vendor_companies', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id,
            'action' => 'privacy.erasure.retention_assessed', 'subject' => (string) $case]);
        $this->actingAs($owner)->postJson('/api/account/privacy-requests/'.$case.'/withdraw')->assertOk();
        $this->actingAs($admin)->getJson($url)->assertStatus(409);
    }

    public function test_non_erasure_and_self_assessment_are_refused(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $case = $this->actingAs($owner)->postJson('/api/account/privacy-requests', [
            'kind' => 'access', 'details' => 'Review access to my personal records.',
        ])->assertCreated()->json('id');
        $this->actingAs($admin)->getJson('/api/admin/privacy-requests/'.$case.'/erasure-assessment')->assertStatus(409);
        $own = $this->postJson('/api/account/privacy-requests', [
            'kind' => 'erasure', 'details' => 'Review my administrator account records.',
        ])->assertCreated()->json('id');
        $this->getJson('/api/admin/privacy-requests/'.$own.'/erasure-assessment')->assertForbidden();
        $this->assertSame(0, DB::table('audit_events')->where('action', 'privacy.erasure.retention_assessed')->count());
    }
}
