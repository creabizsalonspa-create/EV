<?php

namespace Tests\Feature;

use App\Models\Charging\AdmissionProfile;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChargerAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-ingress-key-not-a-deployment-secret';

    private function fixture(string $protocol = '1.6', ?string $pin = null): array
    {
        config(['csms.ingress_allow_local_http' => false, 'csms.ingress_enabled' => true, 'csms.ingress_secret' => self::KEY]);
        Cache::flush();
        $owner = User::factory()->create(['role' => 'vendor']);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Admission fixture', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        $site = VendorSite::create(['company_id' => $company->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Fixture', 'latitude' => 25, 'longitude' => 55]);
        $charger = VendorCharger::create(['company_id' => $company->id, 'site_id' => $site->id, 'asset_reference' => 'AUTH', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => $protocol]);
        $base = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id.'/admission';
        $this->actingAs($owner)->postJson($base, ['identity' => 'ev-test-secure', 'certificate_sha256' => $pin])->assertCreated()->assertJsonMissingPath('credential_digest')->assertJsonPath('enabled', false);
        $reply = $this->postJson($base.'/credential', ['expected_version' => 0, 'days' => 1])->assertCreated()->assertJsonMissingPath('profile.credential_digest')->assertJsonPath('hardware_verified', false)->json();

        return [$owner, $company, $charger, $base, $reply['secret']];
    }

    private function admission(string $secret, array $extra = [], ?string $nonce = null, ?string $key = null, bool $secure = true)
    {
        $p = AdmissionProfile::firstOrFail();
        $body = json_encode(array_replace(['identity' => $p->identity, 'protocol' => $p->protocol, 'tls' => true, 'certificate_sha256' => null], $extra), JSON_THROW_ON_ERROR);
        $auth = 'Basic '.base64_encode($p->identity.':'.$secret);
        $ts = (string) time();
        $nonce ??= (string) Str::uuid();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTPS' => $secure ? 'on' : 'off', 'HTTP_AUTHORIZATION' => $auth, 'HTTP_X_EV_INGRESS_TIME' => $ts, 'HTTP_X_EV_INGRESS_NONCE' => $nonce, 'HTTP_X_EV_INGRESS_SIGNATURE' => hash_hmac('sha256', $ts."\n".$nonce."\n".$auth."\n".$body, $key ?? self::KEY)];

        return $this->call('POST', ($secure ? 'https://localhost' : 'http://localhost').'/api/internal/charger-ingress/authenticate', [], [], [], $headers, $body);
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_signed_tls_admission_rotation_revocation_and_no_credential_disclosure(string $protocol): void
    {
        [$owner,$company,$charger,$base,$secret] = $this->fixture($protocol);
        $this->assertSame(40, strlen($secret));
        $this->assertSame(hash('sha256', $secret), AdmissionProfile::first()->getRawOriginal('credential_digest'));
        $this->getJson($base)->assertOk()->assertJsonMissingPath('profile.credential_digest')->assertJsonMissingPath('secret')->assertHeader('Cache-Control', 'no-store, private');
        $this->admission($secret)->assertNoContent();
        $this->postJson($base.'/credential', ['expected_version' => 0, 'days' => 1])->assertConflict();
        $new = $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertCreated()->json('secret');
        $this->admission($secret)->assertUnauthorized();
        $this->admission($new)->assertNoContent();
        $this->deleteJson($base.'/credential')->assertOk()->assertJsonPath('enabled', false);
        $this->deleteJson($base.'/credential')->assertOk();
        $this->admission($new)->assertUnauthorized();
        $this->assertDatabaseCount('charger_admission_events', 4);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_replayed_or_spoofed_ingress_tls_protocol_and_expiry_are_rejected(): void
    {
        [,,,,$secret] = $this->fixture();
        $nonce = (string) Str::uuid();
        $this->admission($secret, nonce: $nonce)->assertNoContent();
        $this->admission($secret, nonce: $nonce)->assertUnauthorized();
        $this->admission($secret, key: 'forged')->assertUnauthorized();
        $this->admission($secret, ['tls' => false])->assertUnprocessable();
        $this->admission($secret, ['protocol' => '2.0.1'])->assertUnauthorized();
        $this->travel(2)->days();
        $this->admission($secret)->assertUnauthorized();
        config(['csms.ingress_enabled' => false]);
        $this->admission($secret)->assertStatus(503);
    }

    public function test_certificate_pin_current_company_approval_and_owner_scope_are_enforced(): void
    {
        [$owner,$company,$charger,$base,$secret] = $this->fixture(pin: str_repeat('a', 64));
        $this->admission($secret)->assertUnauthorized();
        $this->admission($secret, ['certificate_sha256' => str_repeat('b', 64)])->assertUnauthorized();
        $this->admission($secret, ['certificate_sha256' => str_repeat('a', 64)])->assertNoContent();
        $company->update(['status' => 'suspended']);
        $this->admission($secret, ['certificate_sha256' => str_repeat('a', 64)])->assertUnauthorized();
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertConflict();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($base)->assertNotFound();
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertNotFound();
        $this->actingAs($owner)->deleteJson($base.'/credential')->assertOk();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_production_fails_closed_for_plaintext_or_nondurable_replay_cache(): void
    {
        [,,,,$secret] = $this->fixture();
        config(['csms.ingress_allow_local_http' => false]);
        $this->admission($secret, secure: false)->assertStatus(503);
        config(['csms.ingress_allow_local_http' => true]);
        $this->admission($secret, secure: false)->assertNoContent();
        $this->app->instance('env', 'production');
        $this->admission($secret, secure: false)->assertStatus(503);
        $this->assertInstanceOf(ArrayStore::class, Cache::getStore());
        $this->admission($secret)->assertStatus(503);
    }

    public function test_inventory_staff_cannot_disclose_or_rotate_owner_credentials_and_identity_is_immutable(): void
    {
        [$owner,$company,$charger,$base,$secret] = $this->fixture();
        $this->postJson($base, ['identity' => 'ev-test-changed'])->assertConflict();
        $staff = User::factory()->create();
        VendorCompanyStaff::create(['company_id' => $company->id, 'user_id' => $staff->id, 'email' => $staff->email, 'invited_by' => $owner->id, 'status' => 'active', 'permissions' => ['inventory.read', 'inventory.write']]);
        $response = $this->actingAs($staff)->getJson($base)->assertOk()->assertJsonPath('can_manage', false)->assertJsonMissingPath('profile.credential_digest');
        $this->assertFalse(str_contains($response->getContent(), $secret));
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertNotFound();
        $this->deleteJson($base.'/credential')->assertNotFound();
        $this->assertSame(1, AdmissionProfile::first()->credential_version);
    }

    public function test_dedicated_identity_budget_is_enforced_and_recovers_after_window(): void
    {
        [,,,,$secret] = $this->fixture();
        for ($i = 0; $i < 20; $i++) {
            $this->admission($secret)->assertNoContent();
        }
        $this->admission($secret)->assertStatus(429);
        $this->travel(61)->seconds();
        $this->admission($secret)->assertNoContent();
    }
}
