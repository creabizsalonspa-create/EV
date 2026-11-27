<?php

namespace Tests\Feature;

use App\Models\OcpiRecord;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\ReservationRoaming;
use App\Services\Ocpi\SandboxRoaming;
use Carbon\CarbonImmutable;
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
        $raw['evses'][0]['last_updated'] = $raw['last_updated'];
        $raw['evses'][0]['connectors'][0]['last_updated'] = $raw['last_updated'];
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

    public function test_persisted_receipt_clock_preserves_subseconds_without_rounding_into_the_future(): void
    {
        // Seed before every observed instant: newer-source protection must not
        // discard this regression fixture once the real clock passes its date.
        $this->travelTo(CarbonImmutable::parse('2026-11-19T05:09:00Z'));
        $f = $this->setupReservation();
        $tariff = OcpiRecord::where('module', 'tariffs')->firstOrFail();
        $source = $tariff->payload;
        $zone = date_default_timezone_get();
        try {
            foreach (['Asia/Dubai', 'America/New_York', 'Pacific/Auckland'] as $index => $hostZone) {
                date_default_timezone_set($hostZone);
                $observed = CarbonImmutable::parse('2026-11-19T05:10:00.987654Z')->addSeconds($index);
                $this->travelTo($observed);
                $raw = $source;
                $raw['last_updated'] = $observed->toISOString();
                app(RecordSynchronizer::class)->ingest($f[3], 'tariffs', [$raw]);
                $stored = $tariff->fresh();
                $this->assertSame($observed->toISOString(), $stored->received_at->toISOString(), 'The receipt clock must retain the actual instant, never a rounded future second.');
                $this->assertSame($observed->toISOString(), $stored->source_updated_at->toISOString());
                $this->assertTrue($stored->received_at->lte(now()));
                $this->assertCount(0, $this->writes);
            }
            $this->assertDatabaseCount('ocpi_roaming_orders', 0);
            $this->assertDatabaseCount('ocpi_roaming_commands', 0);
        } finally {
            $this->travelBack();
            date_default_timezone_set($zone);
        }
    }

    public function test_sandbox_tariff_windows_are_strict_utc_under_non_utc_server_timezones(): void
    {
        $f = $this->setupReservation();
        $this->actingAs($f[1]);
        $tariff = OcpiRecord::query()->where('module', 'tariffs')->firstOrFail();
        $source = $tariff->payload;
        $url = '/api/roaming/locations/'.$f[4]->id.'/sandbox-offers';
        $originalZone = date_default_timezone_get();

        try {
            // In Dubai, a Z-free UTC start 30 minutes ahead must be rejected;
            // parsing it as Dubai-local would incorrectly make it historical.
            // In New York, a Z-free UTC end 30 minutes ago must be rejected;
            // interpreting it as New York-local would make it appear future.
            foreach (['Asia/Dubai' => 'start_date_time',
                'America/New_York' => 'end_date_time',
                'UTC' => 'invalid_date',
                'Europe/London' => 'null_start',
                'Pacific/Auckland' => 'null_end'] as $zone => $boundary) {
                date_default_timezone_set($zone);
                $this->travel(1)->seconds();
                $raw = $source;
                $raw['last_updated'] = now()->utc()->toISOString();
                if ($boundary === 'start_date_time') {
                    $raw['start_date_time'] = now()->utc()->addMinutes(30)->format('Y-m-d\TH:i:s');
                } elseif ($boundary === 'end_date_time') {
                    $raw['end_date_time'] = now()->utc()->subMinutes(30)->format('Y-m-d\TH:i:s');
                } elseif ($boundary === 'null_start') {
                    $raw['start_date_time'] = null;
                } elseif ($boundary === 'null_end') {
                    $raw['end_date_time'] = null;
                } else {
                    $raw['start_date_time'] = '2026-02-30T09:00:00';
                }
                if ($boundary === 'invalid_date') {
                    // Canonical OCPI ingestion now rejects impossible dates
                    // before any source replacement. A previously valid
                    // tariff must remain unchanged rather than be treated as
                    // an invalid offer published by this incoming record.
                    $previousPayload = $tariff->fresh()->payload;
                    try {
                        app(RecordSynchronizer::class)->ingest($f[3], 'tariffs', [$raw]);
                        $this->fail('An impossible OCPI source timestamp was accepted.');
                    } catch (OcpiException $error) {
                        $this->assertStringContainsString('start_date_time', $error->getMessage());
                    }
                    $this->assertSame($previousPayload, $tariff->fresh()->payload);
                } else {
                    app(RecordSynchronizer::class)->ingest($f[3], 'tariffs', [$raw]);
                    // SQL datetime columns carry no timezone. A different PHP
                    // default timezone must not move an OCPI UTC instant or
                    // grant an unintended tariff-window offer.
                    $freshTariff = $tariff->fresh();
                    $this->assertSame($raw['last_updated'], $freshTariff->source_updated_at->toISOString());
                    $this->assertTrue($freshTariff->received_at->lte(now()));
                    $this->getJson($url)->assertOk()->assertJsonCount(0, 'offers');
                }
                $this->assertCount(0, $this->writes);
                $this->assertDatabaseCount('ocpi_roaming_orders', 0);
                $this->assertDatabaseCount('ocpi_roaming_commands', 0);
            }

            // A truly current source window remains usable in non-UTC
            // deployment settings. This is still sandbox-only authority.
            date_default_timezone_set('America/New_York');
            $this->travel(1)->seconds();
            $raw = $source;
            $raw['last_updated'] = now()->utc()->toISOString();
            $raw['start_date_time'] = now()->utc()->subMinutes(30)->format('Y-m-d\TH:i:s');
            $raw['end_date_time'] = now()->utc()->addMinutes(30)->format('Y-m-d\TH:i:s');
            $restored = app(RecordSynchronizer::class)->ingest($f[3], 'tariffs', [$raw]);
            $this->assertSame(1, $restored['updated'], 'The restored UTC source tariff must replace the invalid-window snapshot.');
            $this->assertSame($raw['start_date_time'], $tariff->fresh()->payload['start_date_time']);
            // Inspect the actual source offer before the controller catches
            // policy failures; do not accept a silently empty valid offer.
            $this->assertNotEmpty(app(SandboxRoaming::class)->offer($f[4]->fresh(), 'E1', '1', 'T1'));
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'offers')
                ->assertJsonPath('offers.0.reservation_supported', true);
            $this->assertCount(0, $this->writes);
        } finally {
            date_default_timezone_set($originalZone);
            $this->travelBack();
        }
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

    public function test_reservation_needs_coherent_fresh_evse_and_selected_connector_source_times(): void
    {
        $f = $this->setupReservation();
        $provider = $f[3];
        $location = $f[4];
        $this->actingAs($f[1]);
        $url = '/api/roaming/locations/'.$location->id;
        $original = $location->payload;

        foreach (['missing_evse', 'missing_connector', 'stale_connector', 'future_evse', 'impossible_connector', 'child_newer_than_parent'] as $invalid) {
            $this->travel(1)->seconds();
            $raw = $original;
            $raw['last_updated'] = now()->toISOString();
            $raw['evses'][0]['last_updated'] = now()->subSecond()->toISOString();
            $raw['evses'][0]['connectors'][0]['last_updated'] = now()->subSecond()->toISOString();
            if ($invalid === 'missing_evse') {
                unset($raw['evses'][0]['last_updated']);
            } elseif ($invalid === 'missing_connector') {
                unset($raw['evses'][0]['connectors'][0]['last_updated']);
            } elseif ($invalid === 'stale_connector') {
                $raw['evses'][0]['connectors'][0]['last_updated'] = now()->subMinutes(6)->toISOString();
            } elseif ($invalid === 'future_evse') {
                $raw['evses'][0]['last_updated'] = now()->addMinute()->toISOString();
            } elseif ($invalid === 'impossible_connector') {
                $raw['evses'][0]['connectors'][0]['last_updated'] = '2026-02-30T10:00:00Z';
            } else {
                $raw['evses'][0]['last_updated'] = now()->toISOString();
                $raw['evses'][0]['connectors'][0]['last_updated'] = now()->toISOString();
                // Slightly older than the EVSE, but newer than the previous
                // loop's Location revision: rejects incoherent hierarchy.
                $raw['last_updated'] = now()->subMilliseconds(250)->toISOString();
            }
            app(RecordSynchronizer::class)->ingest($provider, 'locations', [$raw]);
            $offer = $this->getJson($url.'/sandbox-offers')
                ->assertOk()
                ->assertJsonPath('reservation_supported', false)
                ->assertJsonPath('offers.0.reservation_supported', false)
                ->json('offers.0');
            $this->assertNotNull($offer, 'An unverified reservation never removes the published start offer.');
            $this->postJson($url.'/sandbox-reservations', [
                'request_key' => (string) Str::uuid(), 'evse_uid' => 'E1',
                'connector_id' => '1', 'tariff_id' => 'T1',
                'offer_hash' => $offer['offer_hash'],
                'terms_version' => ReservationRoaming::TERMS,
                'consent' => true, 'duration_minutes' => 5,
            ])->assertConflict();
            $this->assertCount(0, $this->writes);
            $this->assertDatabaseCount('ocpi_roaming_commands', 0);
            $this->assertDatabaseCount('ocpi_roaming_orders', 0);
        }

        $this->travel(1)->seconds();
        $restored = $original;
        $restored['last_updated'] = now()->toISOString();
        $restored['evses'][0]['last_updated'] = now()->subSecond()->toISOString();
        $restored['evses'][0]['connectors'][0]['last_updated'] = now()->subSecond()->toISOString();
        app(RecordSynchronizer::class)->ingest($provider, 'locations', [$restored]);
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
        $raw['evses'][0]['last_updated'] = $raw['last_updated'];
        $raw['evses'][0]['connectors'][0]['last_updated'] = $raw['last_updated'];
        app(RecordSynchronizer::class)->ingest($f[3], 'locations', [$raw]);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
    }

    public function test_parent_refresh_cannot_close_reservation_without_new_selected_connector_evidence(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertOk();
        $this->travel(1)->seconds();
        $this->partnerCallback($order, 'CANCEL_RESERVATION', 'ACCEPTED');
        $raw = $f[4]->fresh()->payload;
        foreach (['parent_only', 'evse_only', 'selected_connector'] as $refresh) {
            $this->travel(1)->seconds();
            $raw['last_updated'] = now()->toISOString();
            if ($refresh !== 'parent_only') {
                $raw['evses'][0]['last_updated'] = $raw['last_updated'];
            }
            if ($refresh === 'selected_connector') {
                $raw['evses'][0]['connectors'][0]['last_updated'] = $raw['last_updated'];
            }
            app(RecordSynchronizer::class)->ingest($f[3], 'locations', [$raw]);
            $closed = $refresh === 'selected_connector';
            $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', $closed);
            $current = DB::table('ocpi_roaming_orders')->find($order->id);
            if ($closed) {
                $this->assertNull($current->active_target_key);
            } else {
                $this->assertNotNull($current->active_target_key);
                $this->assertNull($current->reservation_closed_at);
            }
            $this->assertCount(3, $this->writes);
        }
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('finance_journals', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_reservation_closure_requires_intact_nonfuture_location_evidence(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertOk();
        $this->partnerCallback($order, 'CANCEL_RESERVATION', 'ACCEPTED');
        $this->freshAvailable($f);
        $location = $f[4]->fresh();
        foreach ([
            'content_hash' => str_repeat('a', 64),
            'received_at' => now()->addDay(),
        ] as $field => $invalid) {
            DB::table('ocpi_records')->where('id', $location->id)->update([$field => $invalid]);
            $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
            $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
            DB::table('ocpi_records')->where('id', $location->id)->update([$field => $location->getRawOriginal($field)]);
        }
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->assertCount(3, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_reservation_closure_requires_acknowledged_deactivation_and_later_connector_evidence(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->partnerCallback($order, 'RESERVE_NOW', 'CANCELED_RESERVATION');
        $this->freshAvailable($f);
        $this->travel(1)->seconds();
        $this->deleteJson($url.'/authority')->assertOk();
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->freshAvailable($f);
        $deactivation = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', 'TOKEN_REVOKE')->first();
        $this->assertNotNull($deactivation->attempted_at);
        DB::table('ocpi_roaming_commands')->where('id', $deactivation->id)->update(['attempted_at' => null]);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        DB::table('ocpi_roaming_commands')->where('id', $deactivation->id)->update(['attempted_at' => $deactivation->attempted_at]);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
        $this->assertNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_contradictory_cancellation_cannot_close_before_expiry_without_independent_source_notice(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertOk();
        DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', 'CANCEL_RESERVATION')->update(['state' => 'partner_rejected', 'transport_result' => 'REJECTED']);
        $this->partnerCallback($order, 'CANCEL_RESERVATION', 'ACCEPTED');
        $this->freshAvailable($f);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
        $this->partnerCallback($order, 'RESERVE_NOW', 'CANCELED_RESERVATION');
        $this->freshAvailable($f);
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
        $this->assertCount(3, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
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

    public function test_reservation_claim_changed_at_insert_does_not_send_or_release_capacity(): void
    {
        $f = $this->setupReservation();
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'insert into "ocpi_roaming_commands"')) {
                $armed = false;
                DB::table('ocpi_roaming_commands')->where('action', 'RESERVE_NOW')->update(['callback_key' => (string) Str::uuid()]);
            }
        });
        $url = '/api/roaming/locations/'.$f[4]->id;
        $offer = $this->actingAs($f[1])->getJson($url.'/sandbox-offers')->assertOk()->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => ReservationRoaming::TERMS, 'consent' => true, 'duration_minutes' => 5];
        $id = $this->postJson($url.'/sandbox-reservations', $body)->assertCreated()->assertJsonPath('state', 'reservation_outcome_unknown')->json('id');
        $this->assertFalse($armed);
        $this->postJson($url.'/sandbox-reservations', $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame('outcome_unknown', DB::table('ocpi_roaming_commands')->value('state'));
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($id)->active_target_key);
        $this->assertCount(0, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_closure_rejects_changed_cancellation_or_deactivation_intent_hash(): void
    {
        $f = $this->setupReservation();
        [$order] = $this->reserve($f);
        $url = '/api/roaming/sandbox-orders/'.$order->id;
        $this->postJson($url.'/cancel-reservation', ['request_key' => (string) Str::uuid(), 'consent' => true])->assertOk();
        $this->partnerCallback($order, 'CANCEL_RESERVATION', 'ACCEPTED');
        $this->freshAvailable($f);
        foreach (['CANCEL_RESERVATION', 'TOKEN_REVOKE'] as $action) {
            $claim = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', $action)->first();
            DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update(['intent_hash' => str_repeat('a', 64)]);
            $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', false);
            $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($order->id)->active_target_key);
            DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update(['intent_hash' => $claim->intent_hash]);
        }
        $this->postJson($url.'/reconcile')->assertOk()->assertJsonPath('reservation_closed', true);
        $this->assertCount(3, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_reservation_attempt_evidence_is_not_erased_by_failed_final_claim(): void
    {
        $f = $this->setupReservation();
        $changed = false;
        DB::listen(function ($query) use (&$changed) {
            if (! $changed && str_starts_with($query->sql, 'insert into "ocpi_roaming_commands"')) {
                $changed = true;
                DB::table('ocpi_roaming_commands')->where('action', 'RESERVE_NOW')->update(['attempted_at' => now()]);
            }
        });
        $this->actingAs($f[1]);
        $offer = $this->getJson('/api/roaming/locations/'.$f[4]->id.'/sandbox-offers')->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => ReservationRoaming::TERMS, 'consent' => true, 'duration_minutes' => 5];
        $url = '/api/roaming/locations/'.$f[4]->id.'/sandbox-reservations';
        $id = $this->postJson($url, $body)->assertCreated()->assertJsonPath('state', 'reservation_outcome_unknown')->json('id');
        $this->assertTrue($changed);
        $this->assertNotNull(DB::table('ocpi_roaming_commands')->where('order_id', $id)->value('attempted_at'));
        $this->assertSame('outcome_unknown', DB::table('ocpi_roaming_commands')->where('order_id', $id)->value('state'));
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($id)->active_target_key);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertCount(0, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_reserve_rejection_after_callback_preserves_both_receipts_and_capacity(): void
    {
        $f = $this->setupReservation();
        Http::swap(new Factory);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            $row = DB::table('ocpi_roaming_orders')->first();
            $this->partnerCallback($row, 'RESERVE_NOW', 'ACCEPTED');

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => ['result' => 'REJECTED', 'timeout' => 30]]);
        });
        $this->actingAs($f[1]);
        $offer = $this->getJson('/api/roaming/locations/'.$f[4]->id.'/sandbox-offers')->json('offers.0');
        $body = ['request_key' => (string) Str::uuid(), 'evse_uid' => 'E1', 'connector_id' => '1', 'tariff_id' => 'T1', 'offer_hash' => $offer['offer_hash'], 'terms_version' => ReservationRoaming::TERMS, 'consent' => true, 'duration_minutes' => 5];
        $url = '/api/roaming/locations/'.$f[4]->id.'/sandbox-reservations';
        $id = $this->postJson($url, $body)->assertCreated()->assertJsonPath('state', 'reservation_outcome_unknown')->json('id');
        $claim = DB::table('ocpi_roaming_commands')->where('order_id', $id)->first();
        $this->assertSame('ACCEPTED', $claim->callback_result);
        $this->assertSame('REJECTED', $claim->transport_result);
        $this->assertSame('outcome_unknown', $claim->state);
        $this->assertNotNull(DB::table('ocpi_roaming_orders')->find($id)->active_target_key);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('id', $id);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
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
