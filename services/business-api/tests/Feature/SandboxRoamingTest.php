<?php

namespace Tests\Feature;

use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class SandboxRoamingTest extends TestCase
{
    use CreatesSandboxRoaming;
    use RefreshDatabase;

    public function test_enforced_contract_review_blocks_new_roaming_before_order_or_transport(): void
    {
        [, $driver, , $provider, $location] = $this->fixture();
        $url = '/api/roaming/locations/'.$location->id;
        $offer = $this->actingAs($driver)->getJson($url.'/sandbox-offers')->assertOk()->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1',
            'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true];
        config(['ocpi.contract_review_required' => true]);
        $this->getJson($url.'/sandbox-offers')->assertConflict()->assertJsonMissingPath('offers');
        $this->postJson($url.'/sandbox-orders', $body)->assertConflict();
        $this->assertDatabaseCount('ocpi_roaming_orders', 0);
        $this->assertDatabaseCount('ocpi_roaming_commands', 0);
        $this->assertCount(0, $this->writes);

        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $this->actingAs($admin)->postJson('/api/ocpi/providers/'.$provider->id.'/contract-review', [
            'decision' => 'review', 'reference_id' => 'SYNTHETIC-ROAMING-001',
            'evidence_sha256' => str_repeat('a', 64), 'valid_until' => now('UTC')->addYear()->toDateString(),
            'expected_revision' => 0, 'idempotency_key' => 'synthetic-roaming-review-001',
            'reason' => 'Internal synthetic document digest review for the approved provider.',
        ])->assertCreated()->assertJsonPath('external_partner_acceptance_verified', false);
        $this->actingAs($driver)->getJson($url.'/sandbox-offers')->assertOk()->assertJsonCount(1, 'offers');
        DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
        $this->getJson($url.'/sandbox-offers')->assertConflict()->assertJsonMissingPath('offers');
        $this->postJson($url.'/sandbox-orders', $body)->assertConflict();
        $this->assertDatabaseCount('ocpi_roaming_orders', 0);
        $this->assertDatabaseCount('ocpi_roaming_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertCount(0, $this->writes);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_withdrawn_tariff_blocks_initial_grants_but_preserves_existing_authorization_and_safe_stop(bool $established): void
    {
        [, $driver, , $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $token = $this->writes[0]['body'];
        $headers = $this->peer();
        $path = '/api/roaming-protocol/2.2.1/tokens/'.$token['uid'].'/authorize?type=APP_USER';
        $body = ['location_id' => 'L1', 'evse_uids' => ['E1']];
        if ($established) {
            $this->withHeaders($headers)->postJson($path, $body)->assertOk()->assertJsonPath('data.allowed', 'ALLOWED');
            $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $this->sourceSessionFixture($provider, $order, $token))->assertCreated();
            $this->withHeaders(['Authorization' => ''])->postJson('/api/roaming/sandbox-orders/'.$id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_active');
        }
        $tariff = OcpiRecord::findOrFail($order->tariff_record_id);
        DB::table('ocpi_catalog_retirements')->insert(['record_id' => $tariff->id, 'content_hash' => $tariff->content_hash, 'credential_generation' => 1, 'payload_encrypted' => $tariff->getRawOriginal('payload'), 'source_updated_at' => $tariff->source_updated_at->format('Y-m-d H:i:s.u'), 'retired_at' => now()->format('Y-m-d H:i:s.u')]);
        $this->withHeaders($headers)->postJson($path, $body)->assertOk()->assertJsonPath('data.allowed', $established ? 'ALLOWED' : 'NOT_ALLOWED');
        $this->withHeaders(['Authorization' => ''])->getJson('/api/roaming/locations/'.$location->id.'/sandbox-offers')->assertOk()->assertJsonCount(0, 'offers');
        if ($established) {
            $this->postJson('/api/roaming/sandbox-orders/'.$id.'/stop', ['request_key' => (string) Str::uuid()])->assertOk();
            $this->assertSame('S1', $this->writes[2]['body']['session_id']);
        } else {
            $republished = $tariff->payload;
            $republished['last_updated'] = now()->addSecond()->toISOString();
            app(RecordSynchronizer::class)->ingest($provider, 'tariffs', [$republished]);
            // Republished content never revives the original withdrawn consent.
            $this->withHeaders($headers)->postJson($path, $body)->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
        }
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
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

    public static function revocationCases(): array
    {
        return ['customer epoch' => ['epoch'], 'customer suspension' => ['customer'], 'partner owner suspension' => ['vendor']];
    }

    #[DataProvider('revocationCases')]
    public function test_customer_revocation_between_token_registration_and_start_prevents_command_dispatch(string $kind): void
    {
        [$owner, $driver, , , $location] = $this->fixture();
        $attempts = 0;
        Http::fake(function ($request) use ($owner, $driver, $kind, &$attempts) {
            if ($request->method() === 'PUT') {
                DB::table('users')->where('id', $kind === 'vendor' ? $owner->id : $driver->id)->update($kind === 'epoch' ? ['auth_epoch' => 1] : ['account_status' => 'suspended']);
            } else {
                $attempts++;
            }

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $request->method() === 'PUT' ? null : ['result' => 'ACCEPTED', 'timeout' => 30]]);
        });
        $offer = $this->actingAs($driver)->getJson('/api/roaming/locations/'.$location->id.'/sandbox-offers')->assertOk()->json('offers.0');
        $response = $this->postJson('/api/roaming/locations/'.$location->id.'/sandbox-orders', ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true]);
        if ($kind === 'vendor') {
            $response->assertCreated()->assertJsonPath('state', 'not_dispatched')->assertJsonPath('start_consent_current', false);
        } else {
            // A revoked request must not receive private order data after the source call.
            $response->assertStatus(409)->assertJsonMissingPath('id')->assertJsonMissingPath('commands');
        }
        $this->assertSame(0, $attempts);
        $command = DB::table('ocpi_roaming_commands')->first();
        $this->assertNull($command->attempted_at);
        $order = DB::table('ocpi_roaming_orders')->first();
        $this->assertSame('not_dispatched', $order->state);
        $this->assertFalse(app(SandboxRoaming::class)->token($order)['valid']);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    #[TestWith(['hash'])]
    #[TestWith(['ciphertext'])]
    #[TestWith(['attempted'])]
    public function test_changed_durable_start_claim_never_dispatches_after_token_registration(string $change): void
    {
        [, $driver, , , $location] = $this->fixture();
        Http::swap(new Factory);
        $requests = [];
        Http::fake(function ($request) use ($change, &$requests) {
            $requests[] = $request->method();
            if ($request->method() === 'PUT') {
                $command = DB::table('ocpi_roaming_commands')->where('action', 'START_SESSION');
                $command->update(match ($change) {
                    'hash' => ['intent_hash' => str_repeat('a', 64)],
                    'ciphertext' => ['payload_ciphertext' => 'corrupted-synthetic-capsule'],
                    'attempted' => ['attempted_at' => now()],
                });
            }

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $request->method() === 'PUT' ? null : ['result' => 'ACCEPTED', 'timeout' => 30]]);
        });
        $url = '/api/roaming/locations/'.$location->id;
        $offer = $this->actingAs($driver)->getJson($url.'/sandbox-offers')->assertOk()->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true];
        $this->postJson($url.'/sandbox-orders', $body)->assertCreated();
        $this->postJson($url.'/sandbox-orders', $body)->assertOk();
        $this->assertSame(['PUT'], $requests);

        $command = DB::table('ocpi_roaming_commands')->first();
        $order = DB::table('ocpi_roaming_orders')->first();
        if ($change === 'attempted') {
            $this->assertNotNull($command->attempted_at);
        } else {
            $this->assertNull($command->attempted_at);
        }
        $this->assertSame('outcome_unknown', $command->state);
        $this->assertSame('start_outcome_unknown', $order->state);
        $this->assertNotNull($order->active_target_key);
        $this->assertNull($command->transport_result);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_start_claim_changed_at_insert_never_registers_a_token_or_sends_a_command(): void
    {
        [, $driver, , , $location] = $this->fixture();
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'insert into "ocpi_roaming_commands"')) {
                $armed = false;
                DB::table('ocpi_roaming_commands')->where('action', 'START_SESSION')->update([
                    'callback_key' => (string) Str::uuid(),
                ]);
            }
        });
        $url = '/api/roaming/locations/'.$location->id;
        $offer = $this->actingAs($driver)->getJson($url.'/sandbox-offers')->assertOk()->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true];
        $this->postJson($url.'/sandbox-orders', $body)->assertCreated()->assertJsonPath('state', 'start_outcome_unknown');
        $this->postJson($url.'/sandbox-orders', $body)->assertOk();
        $this->assertFalse($armed);
        $this->assertCount(0, $this->writes);
        $this->assertSame('outcome_unknown', DB::table('ocpi_roaming_commands')->value('state'));
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->value('active_target_key'));
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    #[TestWith(['callback'])]
    #[TestWith(['authorization'])]
    #[TestWith(['none'])]
    public function test_start_transport_rejection_preserves_earlier_source_authority_and_capacity(string $evidence): void
    {
        [, $driver, , , $location] = $this->fixture();
        Http::swap(new Factory);
        $attempts = [];
        Http::fake(function ($request) use ($evidence, &$attempts) {
            $attempts[] = $request->method();
            if ($request->method() === 'POST') {
                // Inject the persisted evidence an asynchronous peer can commit
                // while the original command transport is still in flight.
                if ($evidence === 'callback') {
                    DB::table('ocpi_roaming_commands')->where('action', 'START_SESSION')->update([
                        'callback_result' => 'ACCEPTED',
                        'callback_hash' => hash('sha256', '{"result":"ACCEPTED"}'),
                        'callback_at' => now(),
                    ]);
                } elseif ($evidence === 'authorization') {
                    DB::table('ocpi_roaming_orders')->update(['authorized_at' => now()]);
                }
            }

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $request->method() === 'PUT' ? null : ['result' => 'REJECTED', 'timeout' => 30]]);
        });
        $url = '/api/roaming/locations/'.$location->id;
        $offer = $this->actingAs($driver)->getJson($url.'/sandbox-offers')->assertOk()->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true];
        $this->postJson($url.'/sandbox-orders', $body)->assertCreated();
        $this->postJson($url.'/sandbox-orders', $body)->assertOk();
        $this->assertSame(['PUT', 'POST'], $attempts);
        $command = DB::table('ocpi_roaming_commands')->first();
        $order = DB::table('ocpi_roaming_orders')->first();
        $this->assertSame('REJECTED', $command->transport_result);
        $this->assertNotNull($command->attempted_at);
        if ($evidence === 'none') {
            $this->assertSame('partner_rejected', $command->state);
            $this->assertSame('partner_rejected', $order->state);
            $this->assertNull($order->active_target_key);
        } else {
            $this->assertSame('outcome_unknown', $command->state);
            $this->assertSame('start_outcome_unknown', $order->state);
            $this->assertNotNull($order->active_target_key);
        }
        if ($evidence === 'callback') {
            $this->assertSame('ACCEPTED', $command->callback_result);
        } elseif ($evidence === 'authorization') {
            $this->assertNotNull($order->authorized_at);
        }
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    #[TestWith(['STOP_SESSION'])]
    #[TestWith(['TOKEN_REVOKE'])]
    public function test_later_roaming_commands_never_erase_a_durable_attempt_or_resend(string $action): void
    {
        [, $driver, , $provider, $location] = $this->fixture();
        [$id] = $this->start($driver, $location);
        $order = DB::table('ocpi_roaming_orders')->find($id);
        $url = '/api/roaming/sandbox-orders/'.$id;
        if ($action === 'STOP_SESSION') {
            $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $this->sourceSessionFixture($provider, $order, $this->writes[0]['body']))->assertCreated();
            $this->withHeaders(['Authorization' => ''])->postJson($url.'/reconcile')->assertOk()->assertJsonPath('state', 'source_active');
        }
        $injected = false;
        DB::listen(function ($query) use ($action, &$injected) {
            if (! $injected && str_starts_with($query->sql, 'insert into "ocpi_roaming_commands"')) {
                $injected = true;
                DB::table('ocpi_roaming_commands')->where('action', $action)->update(['attempted_at' => now()]);
            }
        });
        if ($action === 'STOP_SESSION') {
            $key = (string) Str::uuid();
            $this->postJson($url.'/stop', ['request_key' => $key])->assertOk()->assertJsonPath('can_stop', false);
            $this->postJson($url.'/stop', ['request_key' => $key])->assertOk();
            $this->postJson($url.'/stop', ['request_key' => (string) Str::uuid()])->assertConflict();
        } else {
            $this->deleteJson($url.'/authority')->assertOk()->assertJsonPath('token_state', 'local_revoked')->assertJsonPath('can_complete_source_revocation', false);
            $this->deleteJson($url.'/authority')->assertOk()->assertJsonPath('can_complete_source_revocation', false);
        }
        $this->assertTrue($injected);
        $claim = DB::table('ocpi_roaming_commands')->where('order_id', $id)->where('action', $action)->first();
        $this->assertNotNull($claim->attempted_at);
        $this->assertSame('outcome_unknown', $claim->state);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_stale_authenticated_actor_cannot_create_new_roaming_consent_but_fresh_actor_can(): void
    {
        [, $driver, , , $location] = $this->fixture();
        $service = app(SandboxRoaming::class);
        $offer = $service->offer($location, 'E1', '1', 'T1');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['hash'], 'terms_version' => SandboxRoaming::TERMS, 'consent' => true];
        DB::table('users')->where('id', $driver->id)->update(['auth_epoch' => 1]);
        try {
            $service->create($driver, $location, $body);
            $this->fail('A stale actor must not create a new start intent.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('ocpi_roaming_orders', 0);
        $this->assertSame(1, (int) $service->create($driver->fresh(), $location, $body)['order']->consent_auth_epoch);
        Http::assertNothingSent();
    }
}
