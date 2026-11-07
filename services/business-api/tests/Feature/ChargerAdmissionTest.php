<?php

namespace Tests\Feature;

use App\Http\Controllers\ChargerAdmissionController;
use App\Models\Charging\AdmissionProfile;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\AdmissionLifecycle;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_production_credential_disclosure_requires_https_before_rotating_any_secret(): void
    {
        [$owner, $company, $charger, , $old] = $this->fixture();
        $station = $this->sourceStation($owner);
        VendorSite::findOrFail($charger->site_id)->update(['station_id' => $station->id]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-secure', 'protocol' => '1.6', 'vendor_charger_id' => $charger->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->detectEnvironment(fn () => 'production');
        $controller = app(ChargerAdmissionController::class);
        try {
            $r = Request::create('http://localhost/credential', 'POST', ['expected_version' => 1, 'days' => 1]);
            $r->setUserResolver(fn () => $owner);
            try {
                $controller->credential($r, $company, $charger);
                $this->fail('HTTP must not rotate or disclose a production credential');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
            $this->assertSame(1, AdmissionProfile::first()->credential_version);
            $this->assertSame(hash('sha256', $old), AdmissionProfile::first()->getRawOriginal('credential_digest'));
            $r = Request::create('https://localhost/credential', 'POST', ['expected_version' => 1, 'days' => 1]);
            $r->setUserResolver(fn () => $owner);
            $reply = $controller->credential($r, $company, $charger);
            $this->assertSame(201, $reply->getStatusCode());
            $this->assertSame(2, $reply->getData(true)['profile']['credential_version']);
            $this->assertNotSame($old, $reply->getData(true)['secret']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    private function sourceStation(User $owner): Station
    {
        return Station::create(['vendor_id' => $owner->id, 'name' => 'Identity fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    }

    public function test_canonical_source_conflict_fences_existing_credentials_and_profile_replay(): void
    {
        [$owner, $company, $charger, $base, $secret] = $this->fixture();
        $foreign = $this->sourceStation(User::factory()->create(['role' => 'vendor']));
        DB::table('csms_bindings')->insert(['station_id' => $foreign->id, 'identifier' => 'ev-test-secure', 'protocol' => '1.6', 'vendor_charger_id' => $charger->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->admission($secret)->assertUnauthorized();
        $this->postJson($base, ['identity' => 'ev-test-secure'])->assertConflict();
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertConflict();
        $this->assertSame(1, AdmissionProfile::first()->credential_version);
        $this->assertSame(hash('sha256', $secret), AdmissionProfile::first()->getRawOriginal('credential_digest'));
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_production_admission_requires_durable_nonce_cache_and_exact_owned_canonical_source(): void
    {
        [$owner, $company, $charger, $base, $secret] = $this->fixture();
        config(['cache.default' => 'database']);
        Cache::purge('database');
        $this->app->instance('env', 'production');
        try {
            $this->assertInstanceOf(DatabaseStore::class, Cache::getStore());
            $this->admission($secret)->assertUnauthorized();
            $r = Request::create('https://localhost/credential', 'POST', ['expected_version' => 1, 'days' => 1]);
            $r->setUserResolver(fn () => $owner);
            try {
                app(ChargerAdmissionController::class)->credential($r, $company, $charger);
                $this->fail('Unbound production credentials must not be minted');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            $this->assertSame(1, AdmissionProfile::first()->credential_version);
            $station = $this->sourceStation($owner);
            VendorSite::findOrFail($charger->site_id)->update(['station_id' => $station->id]);
            DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-secure', 'protocol' => '1.6', 'vendor_charger_id' => $charger->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->admission($secret)->assertNoContent();
            DB::table('csms_bindings')->update(['protocol' => '2.0.1']);
            $this->admission($secret)->assertUnauthorized();
            DB::table('csms_bindings')->update(['protocol' => '1.6']);
            VendorSite::findOrFail($charger->site_id)->update(['station_id' => $this->sourceStation($owner)->id]);
            $this->admission($secret)->assertUnauthorized();
        } finally {
            $this->app->instance('env', 'testing');
            config(['cache.default' => 'array']);
        }
    }

    public function test_topology_cannot_adopt_identity_reserved_by_another_inventory_charger(): void
    {
        [$owner, $company, $charger, , $secret] = $this->fixture();
        $station = $this->sourceStation($owner);
        VendorSite::findOrFail($charger->site_id)->update(['station_id' => $station->id]);
        $other = VendorCharger::create(['company_id' => $company->id, 'site_id' => $charger->site_id, 'asset_reference' => 'OTHER', 'manufacturer' => 'Synthetic', 'model' => 'Fixture', 'source' => 'simulator', 'ocpp_protocol' => '1.6']);
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => 'ev-test-secure', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $base = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$other->id;
        $this->getJson($base.'/source-options')->assertOk()->assertJsonPath('total', 0);
        $this->postJson($base.'/evses', ['number' => 1, 'mode' => 'csms', 'binding_id' => $binding, 'source_evse_number' => 0])->assertConflict();
        $this->assertNull(DB::table('csms_bindings')->value('vendor_charger_id'));
        $this->postJson($base.'/admission', ['identity' => 'ev-test-secure'])->assertConflict();
        // The owner must explicitly attach the exact reserved charger first.
        $own = '/api/vendor-ops/companies/'.$company->id.'/chargers/'.$charger->id;
        $this->postJson($own.'/evses', ['number' => 1, 'mode' => 'csms', 'binding_id' => $binding, 'source_evse_number' => 0])->assertCreated();
        $this->admission($secret)->assertNoContent();
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
        $charger->update(['ocpp_protocol' => '2.0.1']);
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 1])->assertConflict();
        $charger->update(['ocpp_protocol' => '1.6']);
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

    public function test_expiry_at_boundary_disables_once_fences_rotation_and_preserves_system_audit(): void
    {
        [$owner,$company,$charger,$base,$secret] = $this->fixture();
        $p = AdmissionProfile::firstOrFail();
        $this->getJson($base)->assertOk()->assertJsonPath('credential_state', 'expiring');
        $this->travelTo($p->expires_at);
        $this->admission($secret)->assertUnauthorized();
        $lifecycle = app(AdmissionLifecycle::class);
        $this->assertSame(1, $lifecycle->scan(100));
        $this->assertSame(0, $lifecycle->scan(100));
        $this->assertFalse($p->fresh()->enabled);
        $this->assertNull($p->fresh()->credential_digest);
        $this->assertSame(2, $p->fresh()->credential_version);
        $this->getJson($base)->assertOk()->assertJsonPath('credential_state', 'expired')->assertJsonPath('hardware_verified', false);
        $this->postJson($base.'/credential', ['expected_version' => 1, 'days' => 30])->assertConflict();
        $this->postJson($base.'/credential', ['expected_version' => 2, 'days' => 30])->assertCreated()->assertJsonPath('profile.credential_version', 3);
        $this->getJson($base)->assertOk()->assertJsonPath('credential_state', 'active');
        $events = $this->getJson($base.'/events')->assertOk()->assertJsonPath('data.1.action', 'expired')->assertJsonPath('data.1.actor_type', 'system')->assertJsonMissingPath('data.0.actor_id')->json();
        $this->assertStringNotContainsString($secret, json_encode($events));
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_credential_history_is_owner_scoped_paginated_and_database_immutable(): void
    {
        [$owner,$company,$charger,$base] = $this->fixture();
        for ($version = 1; $version <= 25; $version++) {
            $this->postJson($base.'/credential', ['expected_version' => $version, 'days' => 30])->assertCreated();
        }
        $this->getJson($base.'/events')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('last_page', 2);
        $this->getJson($base.'/events?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($base.'/events')->assertNotFound();
        foreach (['update', 'delete'] as $verb) {
            try {
                DB::transaction(fn () => $verb === 'update' ? DB::table('charger_admission_events')->update(['action' => 'forged']) : DB::table('charger_admission_events')->delete());
                $this->fail('Audit mutation accepted');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('charger_admission_events', 27);
    }

    public function test_bounded_credential_expiry_command_leaves_fresh_rotations_and_revocations_unchanged(): void
    {
        [$owner,$company,$charger,$base] = $this->fixture();
        $this->artisan('csms:expire-credentials', ['--limit' => '0'])->assertFailed();
        $this->artisan('csms:expire-credentials', ['--limit' => '1001'])->assertFailed();
        $this->artisan('csms:expire-credentials', ['--limit' => '1'])->assertSuccessful();
        $this->assertSame(1, AdmissionProfile::first()->credential_version);
        $this->deleteJson($base.'/credential')->assertOk();
        $this->travel(2)->days();
        $this->artisan('csms:expire-credentials')->assertSuccessful();
        $this->assertSame(2, AdmissionProfile::first()->credential_version);
        $this->assertDatabaseCount('charger_admission_events', 3);
    }
}
