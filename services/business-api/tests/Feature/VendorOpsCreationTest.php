<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorOpsCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function company(User $owner): VendorCompany
    {
        return VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic Operator', 'country' => 'AE', 'trade_license_number' => 'PRIVATE-SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
    }

    private function siteBody(): array
    {
        return ['name' => 'Synthetic site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic address', 'latitude' => 25, 'longitude' => 55, 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_company_creation_replay_is_scoped_payload_bound_and_does_not_repeat_audit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $key = (string) Str::uuid();
        $body = ['legal_name' => 'Synthetic Company', 'country' => 'AE', 'trade_license_number' => 'PRIVATE-SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'idempotency_key' => $key];
        $first = $this->postJson('/api/vendor-ops/companies', $body)->assertCreated()->assertJsonMissingPath('trade_license_number')->json();
        $this->postJson('/api/vendor-ops/companies', array_replace($body, ['idempotency_key' => strtoupper($key)]))->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('id', $first['id']);
        $this->postJson('/api/vendor-ops/companies', array_replace($body, ['legal_name' => 'Changed']))->assertConflict();
        $this->assertDatabaseCount('vendor_companies', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'vendor_ops.company.created')->count());
        $this->actingAs(User::factory()->create())->postJson('/api/vendor-ops/companies', $body)->assertCreated();
        $this->assertDatabaseCount('vendor_companies', 2);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC', json_encode(DB::table('vendor_creation_receipts')->get()));
    }

    public function test_inventory_creation_replays_before_unique_validation_without_enabling_remote_control(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = $this->company($owner);
        $this->actingAs($owner);
        $base = '/api/vendor-ops/companies/'.$company->id;
        $body = $this->siteBody();
        $site = $this->postJson($base.'/sites', $body)->assertCreated()->json();
        $this->postJson($base.'/sites', $body)->assertCreated()->assertJsonPath('id', $site['id']);
        $chargerBody = ['asset_reference' => 'EV-1', 'manufacturer' => 'Synthetic', 'model' => 'Synthetic', 'source' => 'direct_unverified', 'ocpp_protocol' => '2.0.1', 'idempotency_key' => (string) Str::uuid()];
        $charger = $this->postJson($base.'/sites/'.$site['id'].'/chargers', $chargerBody)->assertCreated()->assertJsonPath('remote_control_enabled', false)->json();
        $this->postJson($base.'/sites/'.$site['id'].'/chargers', $chargerBody)->assertCreated()->assertJsonPath('id', $charger['id'])->assertJsonPath('availability', 'unknown');
        $connectorBody = ['connector_number' => 1, 'connector_type' => 'CCS2', 'max_power_kw' => 150, 'idempotency_key' => (string) Str::uuid()];
        $connector = $this->postJson($base.'/chargers/'.$charger['id'].'/connectors', $connectorBody)->assertCreated()->json();
        $this->postJson($base.'/chargers/'.$charger['id'].'/connectors', $connectorBody)->assertCreated()->assertJsonPath('id', $connector['id']);
        $this->assertDatabaseCount('vendor_sites', 1);
        $this->assertDatabaseCount('vendor_chargers', 1);
        $this->assertDatabaseCount('vendor_connectors', 1);
        $this->assertDatabaseCount('csms_bindings', 0);
        $company->update(['status' => 'suspended']);
        $this->postJson($base.'/sites', $body)->assertConflict();
    }

    public function test_revoked_staff_cannot_replay_a_previously_authorized_creation(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create();
        $company = $this->company($owner);
        $membership = VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'status' => 'active', 'permissions' => ['inventory.write']]);
        $body = $this->siteBody();
        $base = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($staff)->postJson($base.'/sites', $body)->assertCreated();
        $this->actingAs($owner)->deleteJson($base.'/staff/'.$membership->id)->assertNoContent();
        $this->actingAs($staff)->postJson($base.'/sites', $body)->assertNotFound();
        $this->assertDatabaseCount('vendor_sites', 1);
    }

    public function test_private_evidence_replay_does_not_write_another_encrypted_file(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = $this->company($owner);
        $company->update(['status' => 'draft']);
        $this->actingAs($owner);
        $key = (string) Str::uuid();
        $path = '/api/vendor-ops/companies/'.$company->id.'/documents';
        $this->getJson('/api/vendor-ops/companies/'.$company->id)->assertOk()->assertJsonPath('submission_readiness.trade_license_present', false);
        $send = fn ($content, $name) => $this->post($path, ['kind' => 'trade_license', 'idempotency_key' => $key, 'document' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json']);
        $first = $send('%PDF-1.4 synthetic', 'first.pdf')->assertCreated()->json();
        $send('%PDF-1.4 synthetic', 'second.pdf')->assertCreated()->assertJsonPath('id', $first['id']);
        $send('%PDF-1.4 changed', 'changed.pdf')->assertConflict();
        $this->assertDatabaseCount('vendor_kyc_documents', 1);
        $this->getJson('/api/vendor-ops/companies/'.$company->id)->assertOk()->assertJsonPath('submission_readiness.trade_license_present', true)->assertJsonPath('submission_readiness.license_valid', true);
        $this->assertCount(1, Storage::disk('local')->allFiles('vendor-kyc'));
        $this->get($path.'/'.$first['id'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertContent('%PDF-1.4 synthetic');
    }

    public function test_stale_staff_consent_cannot_accept_a_reinvitation_with_new_scopes(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $staff = User::factory()->create();
        $company = $this->company($owner);
        $base = '/api/vendor-ops/companies/'.$company->id;
        $this->actingAs($owner)->postJson($base.'/staff', ['email' => $staff->email, 'permissions' => ['inventory.read']])->assertAccepted();
        $m = VendorCompanyStaff::first();
        $accept = '/api/vendor-ops/staff-invitations/'.$m->id.'/accept';
        $this->actingAs($staff)->postJson($accept)->assertUnprocessable();
        $this->actingAs($owner)->deleteJson($base.'/staff/'.$m->id)->assertNoContent();
        $this->postJson($base.'/staff', ['email' => $staff->email, 'permissions' => ['inventory.write']])->assertAccepted();
        $this->actingAs($staff)->postJson($accept, ['expected_version' => 1])->assertConflict();
        $this->getJson('/api/vendor-ops/companies')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($accept, ['expected_version' => 3])->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('permissions.0', 'inventory.write');
        $this->assertNotNull($m->fresh()->accepted_at);
        $this->postJson($accept, ['expected_version' => 1])->assertConflict();
    }

    public function test_completed_creation_receipts_cannot_be_rewritten_or_deleted(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/vendor-ops/companies', ['legal_name' => 'Synthetic Company', 'country' => 'AE', 'trade_license_number' => 'TEST', 'license_expires_on' => now()->addYear()->toDateString(), 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        foreach (['update', 'delete'] as $verb) {
            try {
                DB::transaction(fn () => $verb === 'update' ? DB::table('vendor_creation_receipts')->update(['entity_id' => 999]) : DB::table('vendor_creation_receipts')->delete());
                $this->fail('Creation history changed');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }

    public function test_creation_fingerprint_uses_secret_key_and_supports_retained_rotation_keys(): void
    {
        $this->actingAs(User::factory()->create());
        $body = ['legal_name' => 'Synthetic Company', 'country' => 'AE', 'trade_license_number' => 'PRIVATE', 'license_expires_on' => now()->addYear()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $first = $this->postJson('/api/vendor-ops/companies', $body)->assertCreated()->json();
        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
        $this->postJson('/api/vendor-ops/companies', $body)->assertCreated()->assertJsonPath('id', $first['id']);
        config(['app.previous_keys' => []]);
        $this->postJson('/api/vendor-ops/companies', $body)->assertConflict();
        $this->assertDatabaseCount('vendor_companies', 1);
    }
}
