<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiderAccessHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'TEST card site', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
        $company = VendorCompany::create(['owner_id' => $vendor->id, 'legal_name' => 'TEST operator', 'country' => 'AE', 'trade_license_number' => 'SYNTHETIC', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'approved']);
        VendorSite::create(['company_id' => $company->id, 'station_id' => $station->id, 'name' => 'TEST site', 'country' => 'AE', 'timezone' => 'Asia/Dubai', 'address' => 'Synthetic', 'latitude' => 25, 'longitude' => 55]);
        $token = $this->actingAs($customer)->postJson('/api/rider-access/tokens', ['label' => 'Private card', 'token_type' => 'ISO14443', 'value' => 'AABBCC123456', 'expires_at' => now()->addMonth()->format('Y-m-d\TH:i:s\Z'), 'idempotency_key' => 'history'])->assertCreated()->json('token.id');
        $grant = $this->postJson('/api/rider-access/tokens/'.$token.'/grants', ['station_id' => $station->id])->assertCreated()->json('id');

        return [$customer, $vendor, $station, $company, $token, $grant];
    }

    public function test_customer_and_operator_history_is_private_scoped_and_paginated_without_notes_or_card_values(): void
    {
        [$customer, $vendor, $station, $company, $token, $grant] = $this->fixture();
        $url = '/api/rider-access/tokens/'.$token.'/activity';
        $operator = '/api/vendor-ops/companies/'.$company->id.'/rider-grants/'.$grant.'/activity';
        $this->actingAs($vendor)->postJson('/api/vendor-ops/companies/'.$company->id.'/rider-grants/'.$grant.'/review', ['state' => 'approved', 'note' => 'PRIVATE operator evidence', 'expires_at' => now()->addDay()->format('Y-m-d\TH:i:s\Z')])->assertOk();
        $this->getJson($operator)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(2, 'data')->assertJsonPath('charging_authorized', false)->assertJsonPath('hardware_verified', false)->assertJsonPath('data.0.action', 'grant.approved');
        $this->getJson($url)->assertNotFound();
        $this->actingAs($customer)->deleteJson('/api/rider-access/tokens/'.$token)->assertOk();
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.0.action', 'token.revoked')->assertJsonCount(4, 'data');
        foreach (['PRIVATE', 'AABBCC123456', 'actor_id', 'note', 'token_digest'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        for ($i = 0; $i < 26; $i++) {
            DB::table('rider_authorization_events')->insert(['token_id' => $token, 'grant_id' => null, 'actor_id' => $customer->id, 'action' => 'token.expiry_changed', 'created_at' => now()]);
        }
        $this->getJson($url)->assertJsonCount(25, 'data')->assertJsonPath('total', 30);
        $this->getJson($url.'?page=2')->assertJsonCount(5, 'data');
        $this->getJson($url.'?kind=raw')->assertUnprocessable();
        $this->getJson($url.'?page=-1')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($operator)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($operator)->assertOk()->assertJsonCount(2, 'data');
        $vendor->forceFill(['role' => 'customer'])->save();
        $this->actingAs($vendor->fresh())->getJson($operator)->assertNotFound();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_only_authenticated_known_token_source_attempts_are_recorded_without_inventing_transactions(): void
    {
        [$customer, $vendor, $station, $company, $token, $grant] = $this->fixture();
        config(['csms.rider_enabled' => true, 'csms.session_bridge_enabled' => true, 'csms.ingress_allow_local_http' => true, 'csms.rider_client_id' => 'test-history-source', 'csms.rider_client_secret' => str_repeat('s', 64)]);
        $payload = ['idToken' => 'AABBCC123456', 'idTokenType' => 'ISO14443', 'ocppConnectionName' => 'ev-test-card-history'];
        $this->postJson('/api/internal/rider-authorize', $payload)->assertUnauthorized();
        $bearer = $this->postJson('/api/internal/rider-oauth/token', ['grant_type' => 'client_credentials', 'client_id' => 'test-history-source', 'client_secret' => str_repeat('s', 64), 'audience' => 'creabiz-rider-authorize'])->assertOk()->json('access_token');
        $this->withToken($bearer)->postJson('/api/internal/rider-authorize', $payload)->assertOk()->assertJsonPath('data.allowed', 'BLOCKED');
        DB::table('rider_access_tokens')->where('id', $token)->update(['expires_at' => now()->subDay()]);
        $this->postJson('/api/internal/rider-authorize', $payload)->assertOk()->assertJsonPath('data.allowed', 'EXPIRED');
        DB::table('rider_access_tokens')->where('id', $token)->update(['expires_at' => now()->addDay(), 'provisioning_state' => 'active', 'provisioned_at' => now()]);
        DB::table('rider_charging_grants')->where('id', $grant)->update(['state' => 'approved', 'expires_at' => now()->addHour()]);
        DB::table('csms_bindings')->insert(['station_id' => $station->id, 'identifier' => 'ev-test-card-history', 'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/internal/rider-authorize', $payload)->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        $this->postJson('/api/internal/rider-authorize', array_replace($payload, ['idToken' => 'UNKNOWN123456']))->assertOk()->assertJsonPath('data.allowed', 'BLOCKED');
        $this->assertSame(3, DB::table('rider_authorization_events')->where('action', 'like', 'source.authorization.%')->count());
        $this->assertDatabaseHas('rider_authorization_events', ['token_id' => $token, 'grant_id' => $grant, 'actor_id' => null, 'action' => 'source.authorization.not_allowed', 'note' => null]);
        $this->actingAs($customer)->getJson('/api/rider-access/tokens/'.$token.'/activity')->assertOk()->assertJsonPath('data.0.action', 'source.authorization.not_allowed');
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_source_attempts_require_applied_receipts_and_never_infer_charging_from_approval_or_app_state(string $protocol): void
    {
        [$customer, $vendor, $station, $company, $token, $grant] = $this->fixture();
        $binding = DB::table('csms_bindings')->insertGetId(['station_id' => $station->id, 'identifier' => 'ev-test-card-history', 'protocol' => $protocol, 'created_at' => now(), 'updated_at' => now()]);
        $reservation = Reservation::create(['user_id' => $customer->id, 'station_id' => $station->id, 'starts_at' => now()->startOfHour(), 'status' => 'confirmed', 'idempotency_key' => (string) Str::uuid()]);
        $session = ChargingSession::create(['reservation_id' => $reservation->id, 'status' => 'charging', 'energy_wh' => 0, 'rate_minor_per_kwh' => 100, 'currency' => 'AED']);
        $link = DB::table('csms_session_links')->insertGetId(['charging_session_id' => $session->id, 'binding_id' => $binding, 'rider_token_id' => $token, 'rider_grant_id' => $grant, 'protocol' => $protocol, 'identifier' => 'ev-test-card-history', 'created_at' => now(), 'updated_at' => now()]);
        $url = '/api/rider-access/tokens/'.$token.'/activity?kind=sessions';
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.state', 'awaiting_source')->assertJsonPath('data.0.source_start_observed', false);
        DB::table('csms_session_links')->where('id', $link)->update(['transaction_id' => 'PRIVATE-source-transaction', 'started_at' => now()]);
        $this->getJson($url)->assertJsonPath('data.0.state', 'requires_review');
        DB::table('csms_inbox_events')->insert(['link_id' => $link, 'event_id' => 'start-card-history', 'fingerprint' => str_repeat('a', 64), 'type' => 'started', 'sequence' => 0, 'meter_wh' => 100, 'occurred_at' => now(), 'outcome' => 'applied', 'created_at' => now()]);
        $this->getJson($url)->assertJsonPath('data.0.state', 'source_active')->assertJsonPath('data.0.source_start_observed', true)->assertJsonPath('data.0.source_end_observed', false);
        DB::table('csms_session_links')->where('id', $link)->update(['ended_at' => now()]);
        $this->getJson($url)->assertJsonPath('data.0.state', 'requires_review');
        DB::table('csms_inbox_events')->insert(['link_id' => $link, 'event_id' => 'end-card-history', 'fingerprint' => str_repeat('b', 64), 'type' => 'ended', 'sequence' => 1, 'meter_wh' => 200, 'occurred_at' => now(), 'outcome' => 'applied', 'created_at' => now()]);
        $this->getJson($url)->assertJsonPath('data.0.state', 'source_ended')->assertJsonPath('hardware_verified', false)->assertJsonMissingPath('data.0.transaction_id');
        $operator = '/api/vendor-ops/companies/'.$company->id.'/rider-grants/'.$grant.'/activity?kind=sessions';
        $this->actingAs($vendor)->getJson($operator)->assertOk()->assertJsonPath('data.0.state', 'source_ended');
        DB::table('csms_session_links')->where('id', $link)->update(['closed_without_dispatch_at' => now()]);
        $this->getJson($operator)->assertJsonPath('data.0.state', 'requires_review');
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }
}
