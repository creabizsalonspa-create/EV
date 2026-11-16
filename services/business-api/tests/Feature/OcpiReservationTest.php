<?php

namespace Tests\Feature;

use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\ReservationRoaming;
use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class OcpiReservationTest extends TestCase
{
    use CreatesSandboxRoaming, RefreshDatabase;

    private function setupReservation(): array
    {
        $f = $this->fixture();
        $p = $f[3];
        config(['ocpi.connections.roaming.test_reservations' => true, 'ocpi.connections.roaming.test_reservation_contract' => 'synthetic-reservation-v1']);
        DB::table('ocpi_credential_links')->where('provider_id', $p->id)->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($p)]);

        return $f;
    }

    private function reserve(array $f, int $minutes = 5): array
    {
        [$owner,$driver,,$p,$location] = $f;
        $offer = $this->actingAs($driver)->getJson('/api/roaming/locations/'.$location->id.'/sandbox-offers')->assertOk()->assertJsonPath('reservation_supported', true)->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => ReservationRoaming::TERMS, 'consent' => true, 'duration_minutes' => $minutes];
        $r = $this->postJson('/api/roaming/locations/'.$location->id.'/sandbox-reservations', $body)->assertCreated()->assertJsonPath('intent_kind', 'reservation')->assertJsonPath('state', 'reservation_accepted')->assertJsonPath('reservation_billing_supported', false);

        return [DB::table('ocpi_roaming_orders')->find($r->json('id')), $body];
    }

    private function partnerCallback(object $order, string $action, string $result): void
    {
        $c = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', $action)->first();
        $this->withHeaders($this->peer())->postJson('/api/roaming-protocol/2.2.1/commands/'.$c->callback_key, ['result' => $result])->assertOk();
        $this->withHeader('Authorization', '');
    }

    private function freshAvailable(array $f): void
    {
        $this->travel(1)->seconds();
        $location = $f[4]->fresh();
        $raw = $location->payload;
        $raw['last_updated'] = now()->toISOString();
        app(RecordSynchronizer::class)->ingest($f[3], 'locations', [$raw]);
    }

    public function test_explicit_contract_preauthorization_replay_and_shared_capacity_never_bill(): void
    {
        $f = $this->fixture();
        $location = $f[4];
        $this->actingAs($f[1])->postJson('/api/roaming/locations/'.$location->id.'/sandbox-reservations', ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => str_repeat('a', 64), 'terms_version' => ReservationRoaming::TERMS, 'consent' => true, 'duration_minutes' => 5])->assertConflict();
        $this->assertCount(0, $this->writes);
        config(['ocpi.connections.roaming.test_reservations' => true, 'ocpi.connections.roaming.test_reservation_contract' => 'approved-v1']);
        DB::table('ocpi_credential_links')->where('provider_id', $f[3]->id)->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($f[3])]);
        [$order,$body] = $this->reserve($f);
        $this->assertCount(1, $this->writes);
        $sent = $this->writes[0]['body'];
        $this->assertSame('POST', $this->writes[0]['method']);
        $this->assertStringEndsWith('/commands/RESERVE_NOW', $this->writes[0]['url']);
        $this->assertTrue($sent['token']['valid']);
        $this->assertSame('NEVER', $sent['token']['whitelist']);
        $this->assertSame($order->reservation_id, $sent['reservation_id']);
        $this->assertSame('L1', $sent['location_id']);
        $this->assertSame('E1', $sent['evse_uid']);
        $this->assertArrayNotHasKey('connector_id', $sent);
        $url = '/api/roaming/locations/'.$location->id.'/sandbox-reservations';
        $this->postJson($url, $body)->assertOk()->assertJsonPath('id', $order->id);
        $this->postJson($url, array_replace($body, ['duration_minutes' => 10]))->assertConflict();
        $this->assertCount(1, $this->writes);
        $this->actingAs($f[2])->postJson('/api/roaming/sandbox-orders/'.$order->id.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertNotFound();
        $offer = $this->getJson('/api/roaming/locations/'.$location->id.'/sandbox-offers')->assertOk()->json('offers.0');
        $this->postJson('/api/roaming/locations/'.$location->id.'/sandbox-orders', array_replace($body, ['request_key' => (string) Str::uuid(), 'terms_version' => SandboxRoaming::TERMS, 'offer_hash' => $offer['offer_hash']]))->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_preauthorized_partner_session_can_progress_from_reservation_to_charging_and_stop_without_duplicate_start(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $token = $this->writes[0]['body']['token'];
        $this->partnerCallback($order, 'RESERVE_NOW', 'ACCEPTED');
        $session = $this->sourceSessionFixture($f[3], $order, $token, 'RESERVATION');
        $session['kwh'] = '0';
        $path = '/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1';
        $this->withHeaders($this->peer())->putJson($path, $session)->assertCreated();
        $this->withHeader('Authorization', '')->postJson('/api/roaming/sandbox-orders/'.$order->id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_reserved')->assertJsonPath('can_stop', false);
        $this->travel(1)->seconds();
        $session['last_updated'] = now()->toISOString();
        $session['status'] = 'ACTIVE';
        $session['kwh'] = '0.01';
        $this->withHeaders($this->peer())->putJson($path, $session)->assertOk();
        $this->withHeader('Authorization', '')->postJson('/api/roaming/sandbox-orders/'.$order->id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_active')->assertJsonPath('can_stop', true);
        $this->postJson('/api/roaming/sandbox-orders/'.$order->id.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertConflict();
        $this->assertCount(1, $this->writes);
        $this->postJson('/api/roaming/sandbox-orders/'.$order->id.'/stop', ['request_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('S1', $this->writes[1]['body']['session_id']);
        $this->travel(1)->seconds();
        $session['last_updated'] = now()->toISOString();
        $session['status'] = 'COMPLETED';
        $session['end_date_time'] = now()->toISOString();
        $this->withHeaders($this->peer())->putJson($path, $session)->assertOk();
        $this->withHeader('Authorization', '')->postJson('/api/roaming/sandbox-orders/'.$order->id.'/reconcile')->assertOk()->assertJsonPath('state', 'source_completed')->assertJsonPath('reservation_closed', true)->assertJsonPath('can_preview_test_invoice', false);
        $this->getJson('/api/roaming/sandbox-orders/'.$order->id.'/meter-history')->assertOk()->assertJsonPath('observations.total', 3);
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_cancel_requires_final_callback_deactivation_and_fresh_source_before_capacity_is_released(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $key = (string) Str::uuid();
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/cancel-reservation', ['request_key' => $key, 'consent' => true])->assertOk()->assertJsonPath('physical_stop_implied', false);
        $this->assertCount(3, $this->writes);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->partnerCallback($order, 'CANCEL_RESERVATION', 'ACCEPTED');
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->freshAvailable($f);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('state', 'reservation_closed');
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->postJson($url.'/cancel-reservation', ['request_key' => $key, 'consent' => true])->assertOk();
        $this->assertCount(3, $this->writes);
        $this->start($f[1], $f[4]->fresh());
        $this->assertCount(5, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_source_cancellation_notice_is_separate_immutable_evidence_and_does_not_stop_or_release_by_itself(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $this->partnerCallback($order, 'RESERVE_NOW', 'ACCEPTED');
        $before = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->first();
        $this->partnerCallback($order, 'RESERVE_NOW', 'CANCELED_RESERVATION');
        $this->partnerCallback($order, 'RESERVE_NOW', 'CANCELED_RESERVATION');
        $after = DB::table('ocpi_roaming_commands')->find($before->id);
        $this->assertSame($before->callback_hash, $after->callback_hash);
        $this->assertSame('ACCEPTED', $after->callback_result);
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_source_cancelled', true)->assertJsonPath('reservation_closed', false);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->deleteJson($url.'/authority')->assertOk()->assertJsonPath('physical_stop_implied', false);
        $this->freshAvailable($f);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
        $this->assertCount(2, $this->writes);
    }

    public function test_expiry_or_unknown_cancel_never_releases_or_resends_without_new_source_proof(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $this->travel(6)->minutes();
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        Http::swap(new Factory);
        $calls = 0;
        Http::fake(function ($r) use (&$calls) {
            $calls++;
            if ($r->method() === 'PUT') {
                return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => null]);
            }throw new ConnectionException('Synthetic uncertain transport');
        });
        $key = (string) Str::uuid();
        $this->postJson($url.'/cancel-reservation', ['request_key' => $key, 'consent' => true])->assertOk();
        $this->assertSame(2, $calls);
        $this->postJson($url.'/cancel-reservation', ['request_key' => $key, 'consent' => true])->assertOk();
        $this->postJson($url.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertConflict();
        $this->assertSame(2, $calls);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->freshAvailable($f);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
        $this->assertSame(2, $calls);
    }

    public function test_only_a_freshly_advertised_reservable_evse_can_be_reserved(): void
    {
        $f = $this->setupReservation();
        $location = $f[4];
        $owner = $f[3];
        $this->actingAs($f[1]);
        $url = '/api/roaming/locations/'.$location->id;
        $base = $location->payload;

        foreach ([null, [], ['REMOTE_START_STOP_CAPABLE']] as $capabilities) {
            $this->travel(1)->seconds();
            $changed = $base;
            $changed['last_updated'] = now()->toISOString();
            if ($capabilities === null) {
                unset($changed['evses'][0]['capabilities']);
            } else {
                $changed['evses'][0]['capabilities'] = $capabilities;
            }
            app(RecordSynchronizer::class)->ingest($owner, 'locations', [$changed]);
            $offer = $this->getJson($url.'/sandbox-offers')
                ->assertOk()
                ->assertJsonPath('reservation_supported', false)
                ->assertJsonPath('offers.0.reservation_supported', false)
                ->json('offers.0');
            $this->assertNotNull($offer, 'A published non-reservable EVSE remains visible for permitted start flows.');
            $this->postJson($url.'/sandbox-reservations', [
                'request_key' => (string) Str::uuid(), 'evse_uid' => 'E1',
                'connector_id' => '1', 'tariff_id' => 'T1',
                'offer_hash' => $offer['offer_hash'],
                'terms_version' => ReservationRoaming::TERMS,
                'consent' => true, 'duration_minutes' => 5,
            ])->assertConflict();
            $this->assertCount(0, $this->writes);
            $this->assertDatabaseCount('ocpi_roaming_orders', 0);
            $this->assertDatabaseCount('ocpi_roaming_commands', 0);
        }

        $this->travel(1)->seconds();
        $base['last_updated'] = now()->toISOString();
        app(RecordSynchronizer::class)->ingest($owner, 'locations', [$base]);
        $this->getJson($url.'/sandbox-offers')->assertOk()
            ->assertJsonPath('reservation_supported', true)
            ->assertJsonPath('offers.0.reservation_supported', true);
        $this->assertCount(0, $this->writes);
    }

    public function test_reservation_consent_validation_operator_and_numeric_route_boundaries_send_nothing(): void
    {
        $f = $this->setupReservation();
        $url = '/api/roaming/locations/'.$f[4]->id.'/sandbox-reservations';
        $this->actingAs($f[1]);
        $offer = $this->getJson('/api/roaming/locations/'.$f[4]->id.'/sandbox-offers')->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => ReservationRoaming::TERMS, 'consent' => true, 'duration_minutes' => 5];
        foreach ([['consent' => false], ['duration_minutes' => 1], ['duration_minutes' => 16], ['duration_minutes' => '5.5'], ['terms_version' => SandboxRoaming::TERMS], ['evse_uid' => str_repeat('e', 37)]] as $bad) {
            $this->postJson($url, array_replace($body, $bad))->assertUnprocessable();
        }
        $this->postJson('/api/roaming/locations/'.str_repeat('9', 30).'/sandbox-reservations', $body)->assertNotFound();
        $this->postJson('/api/roaming/sandbox-orders/'.str_repeat('9', 30).'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertNotFound();
        $this->actingAs($f[0])->postJson($url, $body)->assertForbidden();
        $this->assertCount(0, $this->writes);
        $this->assertDatabaseCount('ocpi_roaming_orders', 0);
    }

    public function test_reservation_contract_changes_invalidate_old_authority_without_changing_disabled_fingerprints(): void
    {
        $f = $this->fixture();
        $p = $f[3];
        $original = app(CredentialExchange::class)->configurationHash($p);
        config(['ocpi.connections.roaming.test_reservations' => false, 'ocpi.connections.roaming.test_reservation_contract' => 'ignored']);
        $this->assertSame($original, app(CredentialExchange::class)->configurationHash($p));
        config(['ocpi.connections.roaming.test_reservations' => true, 'ocpi.connections.roaming.test_reservation_contract' => 'approved-v1']);
        $this->assertNotSame($original, app(CredentialExchange::class)->configurationHash($p));
        $this->actingAs($f[1])->getJson('/api/roaming/locations/'.$f[4]->id.'/sandbox-offers')->assertConflict();
        $this->assertCount(0, $this->writes);
    }

    public function test_stale_consent_before_reservation_dispatch_is_blocked_without_a_wire_attempt(): void
    {
        $f = $this->setupReservation();
        $actor = $f[1];
        $location = $f[4];
        $service = app(SandboxRoaming::class);
        $offer = $service->offer($location, 'E1', '1', 'T1');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['hash'], 'terms_version' => ReservationRoaming::TERMS, 'consent' => true];
        $order = $service->create($actor, $location, $body, 5)['order'];
        DB::table('users')->where('id', $actor->id)->increment('auth_epoch');
        try {
            app(ReservationRoaming::class)->reserve($actor, $order);
            $this->fail('Stale consent dispatched');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [401, 409]);
        }
        $this->assertCount(0, $this->writes);
        $this->assertDatabaseCount('ocpi_roaming_commands', 0);
    }

    public function test_location_evidence_before_final_cancel_or_unpublished_source_cannot_release_capacity(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertOk();
        $this->freshAvailable($f);
        $this->travel(1)->seconds();
        $this->partnerCallback($order, 'CANCEL_RESERVATION', 'ACCEPTED');
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->travel(1)->seconds();
        $raw = $f[4]->fresh()->payload;
        $raw['last_updated'] = now()->toISOString();
        $raw['publish'] = false;
        app(RecordSynchronizer::class)->ingest($f[3], 'locations', [$raw]);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->travel(1)->seconds();
        $raw['publish'] = true;
        $raw['last_updated'] = now()->toISOString();
        app(RecordSynchronizer::class)->ingest($f[3], 'locations', [$raw]);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
    }

    public function test_active_source_blocks_cancel_before_reconcile_and_reservation_never_uses_test_billing(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $session = $this->sourceSessionFixture($f[3], $order, $this->writes[0]['body']['token']);
        $this->withHeaders($this->peer())->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/S1', $session)->assertCreated();
        $this->withHeader('Authorization', '');
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertConflict();
        $this->assertCount(1, $this->writes);
        config(['ocpi.test_billing_enabled' => true]);
        $this->getJson($url.'/test-invoice-preview')->assertConflict();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_unknown_reservation_outcome_is_not_redispatched_or_released_on_replay(): void
    {
        $f = $this->setupReservation();
        Http::swap(new Factory);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Synthetic unknown reserve transport');
        });
        $this->actingAs($f[1]);
        $offer = $this->getJson('/api/roaming/locations/'.$f[4]->id.'/sandbox-offers')->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => ReservationRoaming::TERMS, 'consent' => true, 'duration_minutes' => 5];
        $url = '/api/roaming/locations/'.$f[4]->id.'/sandbox-reservations';
        $created = $this->postJson($url, $body)->assertCreated()->assertJsonPath('state', 'reservation_outcome_unknown');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('id', $created->json('id'));
        $this->assertSame(1, $calls);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($created->json('id'))->active_target_key);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_early_source_cancellation_notice_survives_late_transport_receipt(): void
    {
        $f = $this->setupReservation();
        Http::swap(new Factory);
        Http::fake(function () {
            $row = DB::table('ocpi_roaming_orders')->first();
            $this->partnerCallback($row, 'RESERVE_NOW', 'CANCELED_RESERVATION');

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => ['result' => 'ACCEPTED', 'timeout' => 30]]);
        });
        [$order] = $this->reserve($f);
        $this->assertNotNull($order->token_revoked_at);
        $this->assertSame('local_revoked', $order->token_state);
        $this->assertNotNull($order->active_target_key);
        $this->getJson('/api/roaming/sandbox-orders/'.$order->id)->assertOk()->assertJsonPath('reservation_source_cancelled', true)->assertJsonPath('can_cancel_reservation', false);
    }

    public function test_contradictory_cancel_receipts_cannot_create_replacement_commands(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $url = '/api/roaming/sandbox-orders/'.$order->id.'/cancel-reservation';
        $this->postJson($url, ['request_key' => (string) Str::uuid(), 'consent' => true])->assertOk();
        DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', 'CANCEL_RESERVATION')->update(['state' => 'partner_rejected', 'transport_result' => 'REJECTED']);
        $this->partnerCallback($order, 'CANCEL_RESERVATION', 'ACCEPTED');
        $this->postJson($url, ['request_key' => (string) Str::uuid(), 'consent' => true])->assertConflict();
        $this->assertCount(3, $this->writes);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
    }
}
