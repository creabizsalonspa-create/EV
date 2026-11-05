<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiderAccessTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['label' => 'Private card', 'token_type' => 'ISO14443', 'value' => 'AABBCC123456', 'expires_at' => now()->addMonth()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'card-registration'];
    }

    public function test_card_identity_is_encrypted_scoped_and_replay_never_reveals_it_again(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $payload = $this->payload();
        $created = $this->actingAs($customer)->postJson('/api/rider-access/tokens', $payload)->assertCreated()->assertJsonPath('value_once', $payload['value'])->assertJsonPath('charging_authorized', false);
        $id = $created->json('token.id');
        $this->postJson('/api/rider-access/tokens', $payload)->assertOk()->assertJsonMissingPath('value_once')->assertJsonPath('token.id', $id);
        $this->postJson('/api/rider-access/tokens', array_replace($payload, ['label' => 'Changed']))->assertConflict();
        $list = $this->getJson('/api/rider-access/tokens')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString($payload['value'], $list->getContent());
        $this->assertStringNotContainsString($payload['value'], DB::table('rider_access_tokens')->value('token_ciphertext'));
        $this->assertArrayNotHasKey('token_digest', $list->json('data.0'));
        $other = User::factory()->create(['role' => 'customer']);
        $this->actingAs($other)->getJson('/api/rider-access/tokens')->assertJsonCount(0, 'data');
        $this->deleteJson('/api/rider-access/tokens/'.$id)->assertNotFound();
        $this->postJson('/api/rider-access/tokens', $payload)->assertConflict();
    }

    public function test_revocation_is_terminal_idempotent_and_does_not_issue_charger_commands(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $id = $this->actingAs($customer)->postJson('/api/rider-access/tokens', $this->payload())->assertCreated()->json('token.id');
        $this->deleteJson('/api/rider-access/tokens/'.$id)->assertOk()->assertJsonPath('active_sessions_stopped', false);
        $this->deleteJson('/api/rider-access/tokens/'.$id)->assertOk();
        $this->patchJson('/api/rider-access/tokens/'.$id, ['expires_at' => now()->addMonths(2)->format('Y-m-d\TH:i:s\Z')])->assertConflict();
        $this->assertSame(1, DB::table('rider_authorization_events')->where('action', 'token.revoked')->count());
        $this->assertDatabaseCount('csms_session_links', 0);
    }

    public function test_vendor_cannot_register_customer_cards_and_physical_identifier_is_required(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->postJson('/api/rider-access/tokens', $this->payload())->assertForbidden();
        $payload = $this->payload();
        unset($payload['value']);
        $this->actingAs(User::factory()->create(['role' => 'customer']))->postJson('/api/rider-access/tokens', $payload)->assertUnprocessable();
        $this->postJson('/api/rider-access/tokens', array_replace($payload, ['token_type' => 'Central']))->assertCreated();
    }

    public function test_internal_authorization_requires_service_credentials_and_local_adapter(): void
    {
        config(['csms.rider_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.ingress_allow_local_http' => true, 'csms.rider_client_id' => 'creabiz-csms-rider', 'csms.rider_client_secret' => str_repeat('s', 64)]);
        $this->postJson('/api/internal/rider-authorize', ['idToken' => 'AABBCC123456'])->assertUnauthorized();
        $credentials = ['grant_type' => 'client_credentials', 'client_id' => 'creabiz-csms-rider', 'client_secret' => str_repeat('s', 64), 'audience' => 'creabiz-rider-authorize'];
        $this->postJson('/api/internal/rider-oauth/token', array_replace($credentials, ['client_secret' => 'wrong']))->assertUnauthorized();
        $bearer = $this->postJson('/api/internal/rider-oauth/token', $credentials)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('access_token');
        $this->withToken($bearer)->postJson('/api/internal/rider-authorize', ['idToken' => 'AABBCC123456', 'idTokenType' => 'Central', 'ocppConnectionName' => 'ev-test-unregistered'])->assertOk()->assertJsonPath('data.allowed', 'BLOCKED');
        $this->withToken($bearer.'tampered')->postJson('/api/internal/rider-authorize', [])->assertUnauthorized();
        config(['csms.rider_enabled' => false]);
        $this->postJson('/api/internal/rider-oauth/token', $credentials)->assertServiceUnavailable();
        $this->assertDatabaseCount('csms_session_links', 0);
    }

    public function test_station_approval_is_operator_scoped_expiry_bounded_and_private(): void
    {
        $owner = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $station = Station::create(['vendor_id' => $owner->id, 'name' => 'Rider operator fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Synthetic rider operator', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'Fixture', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $id = $this->actingAs($customer)->postJson('/api/rider-access/tokens', $this->payload())->assertCreated()->json('token.id');
        $base = '/api/rider-access/tokens/'.$id.'/grants';
        $grant = $this->postJson($base, ['station_id' => $station->id])->assertCreated()->json('id');
        $this->postJson($base, ['station_id' => $station->id])->assertOk()->assertJsonPath('id', $grant);
        $operator = '/api/vendor-ops/companies/'.$company->id.'/rider-grants';
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($operator)->assertNotFound();
        $body = ['state' => 'approved', 'expires_at' => now()->addDays(2)->format('Y-m-d\TH:i:s\Z'), 'note' => 'PRIVATE operator review evidence'];
        $this->postJson($operator.'/'.$grant.'/review', $body)->assertNotFound();
        $this->actingAs($owner)->postJson($operator.'/'.$grant.'/review', array_replace($body, ['expires_at' => now()->addMonths(2)->format('Y-m-d\TH:i:s\Z')]))->assertUnprocessable();
        $this->postJson($operator.'/'.$grant.'/review', $body)->assertOk()->assertJsonPath('charging_authorized', false);
        $this->assertStringNotContainsString('PRIVATE', DB::table('rider_charging_grants')->value('review_note'));
        $this->assertStringNotContainsString('PRIVATE', $this->getJson($operator)->assertOk()->getContent());
        $this->actingAs($customer)->deleteJson($base.'/'.$grant)->assertOk();
        $this->actingAs($owner)->postJson($operator.'/'.$grant.'/review', $body)->assertConflict();
        $this->assertDatabaseCount('csms_session_links', 0);
    }
}
