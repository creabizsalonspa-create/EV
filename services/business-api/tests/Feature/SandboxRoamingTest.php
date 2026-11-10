<?php

namespace Tests\Feature;

use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class SandboxRoamingTest extends TestCase
{
    use CreatesSandboxRoaming;
    use RefreshDatabase;

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
