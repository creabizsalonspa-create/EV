<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SandboxRoamingTest extends TestCase
{
    use RefreshDatabase;

    private string $peerToken = 'isolated-local-peer-token';

    private array $writes = [];

    private function fixture(): array
    {
        config(['ocpi.test_commands_enabled' => true, 'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic Crea Biz']]]],
            'ocpi.connections.roaming' => ['managed_credentials' => true, 'test_commands' => true, 'test_command_contract' => 'isolated_contract_v1', 'incoming_modules' => ['sessions', 'cdrs'], 'origin' => 'https://partner.example', 'authorization_token' => base64_encode('isolated-bootstrap'), 'environment' => 'sandbox', 'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'CPO']]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $driver = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $p = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Approved sandbox CPO', 'connection_key' => 'roaming', 'authorized' => true, 'version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations', 'tariffs' => 'https://partner.example/tariffs', 'sessions' => 'https://partner.example/sessions', 'cdrs' => 'https://partner.example/cdrs', 'tokens_receiver' => 'https://partner.example/tokens', 'commands_receiver' => 'https://partner.example/commands']]);
        DB::table('ocpi_credential_links')->insert(['provider_id' => $p->id, 'owner_id' => $owner->id, 'generation' => 1, 'state' => 'active', 'configuration_hash' => app(CredentialExchange::class)->configurationHash($p), 'bootstrap_hash' => hash('sha256', 'bootstrap'), 'local_token_digest' => hash('sha256', $this->peerToken), 'local_token_ciphertext' => Crypt::encryptString($this->peerToken), 'remote_token_ciphertext' => Crypt::encryptString('isolated-remote-service-token'), 'exchanged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $identity = ['country_code' => 'NL', 'party_id' => 'ABC', 'last_updated' => now()->subSecond()->toISOString()];
        app(RecordSynchronizer::class)->ingest($p, 'locations', [$identity + ['id' => 'L1', 'publish' => true, 'name' => 'Sandbox location', 'address' => 'Synthetic address', 'city' => 'Test city', 'country' => 'NLD', 'coordinates' => ['latitude' => '52.1', 'longitude' => '4.3'], 'evses' => [['uid' => 'E1', 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'IEC_62196_T2_COMBO', 'tariff_ids' => ['T1']]]]]]]);
        app(RecordSynchronizer::class)->ingest($p, 'tariffs', [$identity + ['id' => 'T1', 'currency' => 'EUR', 'elements' => [['price_components' => [['type' => 'ENERGY', 'price' => '0.25', 'step_size' => 1, 'vat' => '0']]]]]]);
        Http::fake(function ($r) {
            $this->writes[] = ['method' => $r->method(), 'url' => $r->url(), 'body' => $r->data()];
            $this->assertSame('Token '.base64_encode('isolated-remote-service-token'), $r->header('Authorization')[0]);

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $r->method() === 'PUT' ? null : ['result' => 'ACCEPTED', 'timeout' => 30]]);
        });

        return [$owner, $driver, $other, $p, OcpiRecord::where('module', 'locations')->firstOrFail()];
    }

    private function start(User $driver, OcpiRecord $location): array
    {
        $this->actingAs($driver);
        $url = '/api/roaming/locations/'.$location->id;
        $offer = $this->getJson($url.'/sandbox-offers')->assertOk()->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true];
        $reply = $this->postJson($url.'/sandbox-orders', $body)->assertCreated()->assertJsonPath('state', 'start_accepted')->assertJsonPath('billing_eligible', false);

        return [$reply->json('id'), $body, $reply];
    }

    private function peer(): array
    {
        return ['Authorization' => 'Token '.base64_encode($this->peerToken), 'X-Request-ID' => (string) Str::uuid()];
    }

    private function sourceSessionFixture(OcpiProvider $p, object $order, array $token, string $status = 'ACTIVE'): array
    {
        $raw = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'S1', 'start_date_time' => now()->toISOString(), 'last_updated' => now()->toISOString(), 'status' => $status, 'kwh' => '0.01', 'currency' => 'EUR', 'location_id' => 'L1', 'evse_uid' => 'E1', 'connector_id' => '1', 'total_cost' => ['excl_vat' => '0.0025'], 'authorization_reference' => $order->authorization_reference, 'cdr_token' => array_intersect_key($token, array_flip(['country_code', 'party_id', 'uid', 'type', 'contract_id']))];
        if ($status === 'COMPLETED') {
            $raw['end_date_time'] = now()->addSecond()->toISOString();
        }

        return $raw;
    }

    public function test_explicit_sandbox_consent_registers_never_offline_token_and_dispatches_once_without_financial_writes(): void
    {
        [$owner,$driver,$other,$p,$location] = $this->fixture();
        [$id,$body,$reply] = $this->start($driver, $location);
        $this->assertCount(2, $this->writes);
        $this->assertSame('NEVER', $this->writes[0]['body']['whitelist']);
        $this->assertSame('APP_USER', $this->writes[0]['body']['type']);
        $uid = $this->writes[0]['body']['uid'];
        $this->assertStringNotContainsString($uid, $reply->getContent());
        $this->assertStringNotContainsString('ciphertext', $reply->getContent());
        $this->postJson('/api/roaming/locations/'.$location->id.'/sandbox-orders', $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertCount(2, $this->writes);
        $this->actingAs($other)->getJson('/api/roaming/sandbox-orders/'.$id)->assertNotFound();
        $this->actingAs($driver)->postJson('/api/roaming/sandbox-orders/'.$id.'/stop', ['request_key' => (string) Str::uuid()])->assertConflict();
        $this->actingAs($owner)->getJson('/api/ocpi/providers/'.$p->id.'/sandbox-orders')->assertOk()->assertJsonPath('data.0.can_stop', false);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_peer_authorization_is_location_bound_one_time_and_callbacks_cannot_invent_source_sessions(): void
    {
        [, $driver, , $p,$location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $path = '/api/roaming-protocol/2.2.1/tokens/'.$token['uid'].'/authorize?type=APP_USER';
        $this->withHeaders(['Authorization' => ''])->postJson($path, ['location_id' => 'L1', 'evse_uids' => ['E1']])->assertUnauthorized();
        $this->withHeaders($this->peer())->postJson($path, ['location_id' => 'WRONG', 'evse_uids' => ['E1']])->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        $headers = $this->peer();
        $body = ['location_id' => 'L1', 'evse_uids' => ['E1']];
        $this->withHeaders($headers)->postJson($path, $body)->assertOk()->assertJsonPath('data.allowed', 'ALLOWED');
        $this->withHeaders($headers)->postJson($path, $body)->assertOk()->assertJsonPath('data.allowed', 'ALLOWED');
        $this->withHeaders($this->peer())->postJson($path, $body)->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        $callback = parse_url($this->writes[1]['body']['response_url'], PHP_URL_PATH);
        $this->withHeaders($this->peer())->postJson($callback, ['result' => 'ACCEPTED'])->assertOk();
        $this->postJson($callback, ['result' => 'ACCEPTED'])->assertOk();
        $this->postJson($callback, ['result' => 'FAILED'])->assertConflict();
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->source_session_record_id);
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/stop', ['request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_actual_managed_session_and_cdr_receipts_reconcile_owned_progress_and_stop_without_local_billing(): void
    {
        [, $driver, , $p,$location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $this->withHeaders($this->peer())->postJson('/api/roaming-protocol/2.2.1/tokens/'.$token['uid'].'/authorize?type=APP_USER', ['location_id' => 'L1', 'evse_uids' => ['E1']])->assertOk()->assertJsonPath('data.allowed', 'ALLOWED');
        $session = $this->sourceSessionFixture($p, $order, $token);
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $session)->assertCreated();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_active')->assertJsonPath('energy_wh', 10)->assertJsonPath('can_stop', true);
        $stop = ['request_key' => (string) Str::uuid()];
        $this->postJson('/api/roaming/sandbox-orders/'.$id.'/stop', $stop)->assertOk();
        $before = count($this->writes);
        $this->postJson('/api/roaming/sandbox-orders/'.$id.'/stop', $stop)->assertOk();
        $this->assertCount($before, $this->writes);
        $this->assertSame('S1', $this->writes[2]['body']['session_id']);
        $session['last_updated'] = now()->addSecond()->toISOString();
        $session['status'] = 'COMPLETED';
        $session['end_date_time'] = now()->addSecond()->toISOString();
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $session)->assertOk();
        $cdr = ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'C1', 'session_id' => 'S1', 'last_updated' => now()->addSecond()->toISOString(), 'start_date_time' => $session['start_date_time'], 'end_date_time' => $session['end_date_time'], 'currency' => 'EUR', 'total_energy' => '0.01', 'total_time' => '0.001', 'total_cost' => ['excl_vat' => '0.0025'], 'cdr_location' => ['id' => 'L1', 'evse_uid' => 'E1', 'connector_id' => '1'], 'cdr_token' => $session['cdr_token'], 'authorization_reference' => $order->authorization_reference];
        $this->postJson('/api/roaming-protocol/2.2.1/cdrs', $cdr)->assertCreated();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_completed')->assertJsonPath('reconciliation_state', 'source_consistent')->assertJsonPath('local_invoice_created', false);
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->active_target_key);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_remote_start_consent_does_not_require_an_optional_online_token_callback(): void
    {
        [, $driver, , $p,$location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $session = $this->sourceSessionFixture($p, $order, $token);
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $session)->assertCreated();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_active')->assertJsonPath('can_stop', true);
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->authorized_at);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_unknown_transport_is_never_redispatched_and_live_or_disabled_authority_is_denied(): void
    {
        [, $driver, , $p, $location] = $this->fixture();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('PRIVATE transport');
        });
        [$id, $body] = (function () use ($driver, $location) {
            $this->actingAs($driver);
            $url = '/api/roaming/locations/'.$location->id;
            $offer = $this->getJson($url.'/sandbox-offers')->assertOk()->json('offers.0');
            $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true];
            $response = $this->postJson($url.'/sandbox-orders', $body)->assertCreated();
            $this->assertStringNotContainsString('PRIVATE', $response->getContent());

            return [$response->json('id'), $body];
        })();
        $this->assertSame(1, $attempts);
        $this->postJson('/api/roaming/locations/'.$location->id.'/sandbox-orders', $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, $attempts);
        $this->assertNull(DB::table('ocpi_roaming_commands')->where('order_id', $id)->where('action', 'START_SESSION')->firstOrFail()->attempted_at);
        config(['ocpi.test_commands_enabled' => false]);
        $this->getJson('/api/roaming/locations/'.$location->id.'/sandbox-offers')->assertConflict();
        config(['ocpi.test_commands_enabled' => true, 'ocpi.connections.roaming.environment' => 'production']);
        $this->getJson('/api/roaming/locations/'.$location->id.'/sandbox-offers')->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_revocation_denies_new_authority_without_hiding_an_owned_active_session_from_stop(): void
    {
        [, $driver, , $p, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $session = $this->sourceSessionFixture($p, $order, $token);
        $session['cdr_token']['uid'] = 'FOREIGN';
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $session)->assertCreated();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('can_stop', false);
        $session['cdr_token']['uid'] = $token['uid'];
        $session['last_updated'] = now()->addSecond()->toISOString();
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $session)->assertOk();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('can_stop', true);
        $this->deleteJson('/api/roaming/sandbox-orders/'.$id.'/authority')->assertOk()->assertJsonPath('can_stop', true)->assertJsonPath('physical_stop_implied', false);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($id)->active_target_key);
        $before = count($this->writes);
        $this->deleteJson('/api/roaming/sandbox-orders/'.$id.'/authority')->assertOk();
        $this->assertCount($before, $this->writes);
        $this->withHeaders($this->peer())->postJson('/api/roaming-protocol/2.2.1/tokens/'.$token['uid'].'/authorize?type=APP_USER', ['location_id' => 'L1', 'evse_uids' => ['E1']])->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/stop', ['request_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('S1', end($this->writes)['body']['session_id']);
        $this->assertDatabaseCount('finance_invoices', 0);
    }
}
