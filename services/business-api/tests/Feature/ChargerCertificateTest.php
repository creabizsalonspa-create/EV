<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesChargerCertificate;
use Tests\TestCase;

class ChargerCertificateTest extends TestCase
{
    use CreatesChargerCertificate, RefreshDatabase;

    private const KEY = 'synthetic-certificate-ingress-key-at-least32';

    private function fixture(string $protocol = '1.6'): array
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic certificate owner', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'name' => 'Certificate fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'CERT', 'manufacturer' => 'Synthetic', 'model' => 'Offline', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $base = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/admission';
        $profile = $this->actingAs($owner)->postJson($base, ['identity' => 'ev-test-certificate'])->assertCreated()->json();
        [$pem] = $this->certificatePair();
        $intent = ['idempotency_key' => (string) Str::uuid(), 'expected_actor_id' => $owner->id, 'expected_profile_id' => $profile['id'], 'expected_version' => 0, 'reason' => 'Explicit isolated certificate enrollment', 'consent' => true, 'public_certificate' => $pem];
        config(['csms.ingress_enabled' => true, 'csms.ingress_allow_local_http' => true, 'csms.ingress_secret' => self::KEY]);

        return [$owner, $base, $profile, $intent, $charger, $company];
    }

    private function ingress(string $protocol, string $secret, string $fingerprint)
    {
        $raw = json_encode(['identity' => 'ev-test-certificate', 'protocol' => $protocol, 'tls' => true, 'certificate_sha256' => $fingerprint], JSON_THROW_ON_ERROR);
        $time = (string) time();
        $nonce = (string) Str::uuid();
        $auth = 'Basic '.base64_encode('ev-test-certificate:'.$secret);

        return $this->call('POST', '/api/internal/charger-ingress/authenticate', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => $auth, 'HTTP_X_EV_INGRESS_TIME' => $time, 'HTTP_X_EV_INGRESS_NONCE' => $nonce, 'HTTP_X_EV_INGRESS_SIGNATURE' => hash_hmac('sha256', $time."\n".$nonce."\n".$auth."\n".$raw, self::KEY)], $raw);
    }

    #[TestWith(['1.6'])]
    #[TestWith(['2.0.1'])]
    public function test_verified_certificate_lifecycle_replay_rotation_revocation_and_handshake_denial(string $protocol): void
    {
        [$owner,$base,$profile,$intent] = $this->fixture($protocol);
        $result = $this->postJson($base.'/certificates/enroll', $intent)->assertOk()->assertJsonPath('device_commands_sent', false)->assertJsonPath('connection_close_requested', false)->assertJsonPath('result_version', 1)->json();
        $this->postJson($base.'/certificates/enroll', $intent)->assertOk()->assertJsonPath('certificate_id', $result['certificate_id']);
        $this->postJson($base.'/certificates/enroll', array_replace($intent, ['reason' => 'Another payload with the same key']))->assertConflict();
        $this->assertDatabaseHas('charger_admission_profiles', ['id' => $profile['id'], 'enabled' => false, 'credential_digest' => null]);
        $rotation = $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 90])->assertCreated()->json();
        $this->assertTrue(CarbonImmutable::parse($rotation['profile']['expires_at'])->equalTo(CarbonImmutable::parse($result['certificate']['not_after'])));
        $this->ingress($protocol, $rotation['secret'], $result['certificate']['fingerprint'])->assertNoContent();
        $this->ingress($protocol, $rotation['secret'], str_repeat('a', 64))->assertUnauthorized();
        $this->getJson($base.'/certificates')->assertOk()->assertJsonPath('data.0.state', 'expiring')->assertDontSee('BEGIN CERTIFICATE')->assertDontSee($intent['reason']);
        $revoke = ['idempotency_key' => (string) Str::uuid(), 'expected_actor_id' => $owner->id, 'expected_profile_id' => $profile['id'], 'expected_version' => 2, 'reason' => 'Explicit local certificate revocation', 'consent' => true, 'certificate_id' => $result['certificate_id']];
        config(['csms.certificate_management_enabled' => false]);
        $this->postJson($base.'/certificates/revoke', $revoke)->assertOk()->assertJsonPath('result_version', 3);
        $this->postJson($base.'/certificates/revoke', $revoke)->assertOk();
        $this->ingress($protocol, $rotation['secret'], $result['certificate']['fingerprint'])->assertUnauthorized();
        $this->postJson($base.'/credential', ['expected_version' => 3, 'days' => 1])->assertConflict();
        $this->postJson($base, ['identity' => 'ev-test-certificate', 'certificate_sha256' => null])->assertConflict();
        $this->getJson($base.'/certificates')->assertOk()->assertJsonPath('data.0.state', 'revoked');
        $this->assertDatabaseCount('charger_certificates', 1);
        $this->assertDatabaseCount('charger_certificate_actions', 2);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_renewal_retains_history_invalidates_old_pin_and_does_not_restore_old_credentials(): void
    {
        [, $base, $profile, $intent] = $this->fixture('2.0.1');
        $first = $this->postJson($base.'/certificates/enroll', $intent)->assertOk()->json();
        $old = $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertCreated()->json('secret');
        [$pem] = $this->certificatePair();
        $renew = array_replace($intent, ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 2, 'public_certificate' => $pem]);
        $second = $this->postJson($base.'/certificates/enroll', $renew)->assertOk()->assertJsonPath('result_version', 3)->json();
        $this->ingress('2.0.1', $old, $first['certificate']['fingerprint'])->assertUnauthorized();
        $new = $this->postJson($base.'/credential', ['expected_version' => 3, 'days' => 1])->assertCreated()->json('secret');
        $this->ingress('2.0.1', $new, $second['certificate']['fingerprint'])->assertNoContent();
        $this->ingress('2.0.1', $new, $first['certificate']['fingerprint'])->assertUnauthorized();
        $this->postJson($base.'/certificates/enroll', $intent)->assertOk()->assertJsonPath('certificate_id', $first['certificate_id']);
        $this->assertDatabaseHas('charger_admission_profiles', ['id' => $profile['id'], 'managed_certificate_id' => $second['certificate_id']]);
        $this->getJson($base.'/certificates')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.state', 'superseded');
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_enrollment_flag_and_context_fail_closed_but_explicit_unknown_intent_resolution_remains_available(): void
    {
        [$owner, $base, $profile, $intent] = $this->fixture();
        config(['csms.certificate_management_enabled' => false]);
        $this->postJson($base.'/certificates/enroll', $intent)->assertStatus(503);
        $this->assertDatabaseCount('charger_certificates', 0);
        $this->postJson($base.'/certificates/resolve', ['idempotency_key' => $intent['idempotency_key'], 'expected_actor_id' => $owner->id, 'expected_profile_id' => $profile['id'], 'expected_action' => 'enroll', 'cancel_unissued' => true])->assertOk()->assertJsonPath('state', 'cancelled');
        config(['csms.certificate_management_enabled' => true]);
        $this->postJson($base.'/certificates/enroll', array_replace($intent, ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 1]))->assertConflict();
        $this->assertDatabaseCount('charger_certificates', 0);
    }

    public function test_cancelled_unknown_request_blocks_a_delayed_enrollment_and_wrong_account_cannot_resolve(): void
    {
        [$owner,$base,$profile,$intent] = $this->fixture();
        $resolve = ['idempotency_key' => $intent['idempotency_key'], 'expected_actor_id' => $owner->id, 'expected_profile_id' => $profile['id'], 'expected_action' => 'enroll', 'cancel_unissued' => true];
        $this->postJson($base.'/certificates/resolve', $resolve)->assertOk()->assertJsonPath('state', 'cancelled');
        $this->postJson($base.'/certificates/enroll', $intent)->assertConflict();
        $this->postJson($base.'/certificates/resolve', $resolve)->assertOk();
        $this->postJson($base.'/certificates/resolve', array_replace($resolve, ['expected_actor_id' => 999]))->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($base.'/certificates')->assertNotFound();
        $this->postJson($base.'/certificates/resolve', $resolve)->assertNotFound();
        $this->assertDatabaseCount('charger_certificates', 0);
        $this->assertDatabaseCount('charger_certificate_actions', 1);
    }

    #[TestWith(['wrong_identity'])]
    #[TestWith(['server_purpose'])]
    #[TestWith(['weak_key'])]
    #[TestWith(['untrusted_issuer'])]
    #[TestWith(['private_key'])]
    public function test_untrusted_or_incompatible_public_certificate_never_changes_admission(string $kind): void
    {
        [, $base, $profile, $intent] = $this->fixture();
        if ($kind === 'wrong_identity') {
            [$intent['public_certificate']] = $this->certificatePair('another-charger');
        }
        if ($kind === 'server_purpose') {
            [$intent['public_certificate']] = $this->certificatePair('ev-test-certificate', 'serverAuth');
        }
        if ($kind === 'weak_key') {
            [$intent['public_certificate']] = $this->certificatePair('ev-test-certificate', 'clientAuth', 1024);
        }
        if ($kind === 'untrusted_issuer') {
            $this->certificatePair();
        }
        if ($kind === 'private_key') {
            $intent['public_certificate'] = "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----";
        }
        $this->postJson($base.'/certificates/enroll', $intent)->assertUnprocessable();
        $this->assertDatabaseCount('charger_certificates', 0);
        $this->assertDatabaseCount('charger_certificate_actions', 0);
        $this->assertDatabaseHas('charger_admission_profiles', ['id' => $profile['id'], 'credential_version' => 0, 'managed_certificate_id' => null]);
    }

    #[TestWith([6])]
    #[TestWith([-1])]
    public function test_expired_or_not_yet_valid_certificate_cannot_be_enrolled(int $days): void
    {
        [, $base, $profile, $intent] = $this->fixture();
        $this->travel($days)->days();
        $this->postJson($base.'/certificates/enroll', $intent)->assertUnprocessable();
        $this->assertDatabaseCount('charger_certificates', 0);
        $this->travelBack();
    }

    public function test_inventory_staff_can_read_public_metadata_but_only_owner_or_admin_can_manage_certificates(): void
    {
        [$owner, $base, $profile, $intent, $charger, $company] = $this->fixture();
        $staff = User::factory()->create();
        VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email, 'invited_by' => $owner->id, 'status' => 'active', 'permissions' => ['inventory.read', 'inventory.write']]);
        $this->actingAs($staff)->getJson($base.'/certificates')->assertOk()->assertJsonPath('can_manage', false);
        $this->postJson($base.'/certificates/enroll', array_replace($intent, ['expected_actor_id' => $staff->id]))->assertNotFound();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson($base.'/certificates/enroll', array_replace($intent, ['expected_actor_id' => $admin->id]))->assertOk()->assertJsonPath('actor_id', $admin->id);
        $this->actingAs($staff)->getJson($base.'/certificates')->assertOk()->assertJsonPath('can_manage', false)->assertDontSee('BEGIN CERTIFICATE');
        $this->assertDatabaseCount('charger_certificates', 1);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_expiry_or_issuer_removal_blocks_managed_admission_and_history_is_immutable(): void
    {
        [, $base, $profile, $intent] = $this->fixture();
        $cert = $this->postJson($base.'/certificates/enroll', $intent)->assertOk()->json('certificate');
        $rotation = $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertCreated()->json();
        config(['csms.certificate_issuer_pins' => []]);
        $this->ingress('1.6', $rotation['secret'], $cert['fingerprint'])->assertUnauthorized();
        $this->postJson($base.'/credential', ['expected_version' => 2, 'days' => 1])->assertStatus(503);
        $this->travel(6)->days();
        $this->getJson($base.'/certificates')->assertOk()->assertJsonPath('data.0.state', 'expired');
        $this->postJson($base.'/credential', ['expected_version' => 2, 'days' => 1])->assertConflict();
        $this->travelBack();
        foreach (['charger_certificates', 'charger_certificate_actions'] as $table) {
            foreach (['update', 'delete'] as $mutation) {
                try {
                    DB::transaction(fn () => $mutation === 'delete' ? DB::table($table)->delete() : DB::table($table)->update(['profile_id' => $profile['id']]));
                    $this->fail('Immutable certificate history changed.');
                } catch (QueryException) {
                    $this->assertDatabaseCount($table, 1);
                }
            }
        }
    }
}
