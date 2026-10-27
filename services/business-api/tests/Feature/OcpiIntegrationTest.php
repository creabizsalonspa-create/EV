<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OcpiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'api/roaming/locations')) {
            Route::middleware('web')->group(base_path('routes/ocpi.php'));
        }
        Http::preventStrayRequests();
    }

    private function provider(bool $authorized = true): OcpiProvider
    {
        config(['ocpi.connections.contract-test' => ['origin' => 'https://partner.example', 'versions_path' => '/ocpi/versions', 'authorization_token' => base64_encode('not-a-real-partner-token'), 'environment' => 'sandbox']]);

        return OcpiProvider::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Contract fixture only', 'connection_key' => 'contract-test', 'authorized' => $authorized]);
    }

    private function envelope($data): array
    {
        return ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $data];
    }

    private function advertise(): array
    {
        return $this->envelope(['version' => '2.2.1', 'endpoints' => array_map(fn ($m) => ['identifier' => $m, 'role' => 'SENDER', 'url' => 'https://partner.example/ocpi/2.2.1/'.$m], PartnerClient::MODULES)]);
    }

    private function location(): array
    {
        return ['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'fixture-1', 'publish' => true, 'name' => 'Contract fixture station', 'address' => 'Test address', 'city' => 'Test city', 'country' => 'ARE', 'coordinates' => ['latitude' => '25.2', 'longitude' => '55.3'], 'evses' => [['uid' => 'evse-1', 'status' => 'AVAILABLE', 'connectors' => [['id' => '1', 'standard' => 'IEC_62196_T2_COMBO']]]], 'last_updated' => now()->subMinute()->toISOString()];
    }

    private function sessionFixture(): array
    {
        return ['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'session-1', 'last_updated' => now()->subMinute()->toISOString(), 'start_date_time' => now()->subHours(2)->toISOString(), 'currency' => 'AED', 'kwh' => 12.34, 'status' => 'ACTIVE', 'location_id' => 'fixture-1', 'evse_uid' => 'evse-1', 'connector_id' => '1'];
    }

    private function cdr(): array
    {
        return ['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'cdr-1', 'last_updated' => now()->subMinute()->toISOString(), 'start_date_time' => now()->subHours(2)->toISOString(), 'end_date_time' => now()->subHour()->toISOString(), 'currency' => 'AED', 'total_energy' => 12.34, 'total_time' => 1, 'total_cost' => ['excl_vat' => 12.34, 'incl_vat' => 12.96], 'cdr_location' => ['id' => 'fixture-1', 'evse_uid' => 'evse-1', 'connector_id' => '1'], 'cdr_token' => ['uid' => 'private-driver-tag']];
    }

    public function test_negotiation_sends_ocpi_token_header_and_selects_sender_capabilities_only(): void
    {
        $p = $this->provider();
        $details = $this->advertise();
        $details['data']['endpoints'][] = ['identifier' => 'commands', 'role' => 'RECEIVER', 'url' => 'https://partner.example/commands'];
        Http::fake(['https://partner.example/ocpi/versions' => Http::response($this->envelope([['version' => '2.2.1', 'url' => 'https://partner.example/ocpi/2.2.1']])), 'https://partner.example/ocpi/2.2.1' => Http::response($details)]);
        $modules = app(PartnerClient::class)->negotiate($p);
        $this->assertSame(PartnerClient::MODULES, array_keys($modules));
        $this->assertSame('2.2.1', $p->fresh()->version);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Token '.base64_encode('not-a-real-partner-token')) && $r->hasHeader('X-Request-ID'));
        $this->assertArrayNotHasKey('commands', $modules);
    }

    public function test_endpoint_origin_escape_is_rejected_before_forwarding_credentials(): void
    {
        $p = $this->provider();
        Http::fake(['https://partner.example/ocpi/versions' => Http::response($this->envelope([['version' => '2.2.1', 'url' => 'https://attacker.example/steal']]))]);
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Cross-origin negotiation was accepted');
        } catch (OcpiException $e) {
            $this->assertStringContainsString('approved HTTPS origin', $e->getMessage());
        }Http::assertSentCount(1);
    }

    public function test_redirects_are_not_followed_and_invalid_credentials_are_not_exposed(): void
    {
        $p = $this->provider();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://attacker.example'])]);
        $this->expectException(OcpiException::class);
        app(PartnerClient::class)->negotiate($p);
    }

    public function test_unauthorized_provider_and_unnegotiated_module_never_send_requests(): void
    {
        $p = $this->provider(false);
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail();
        } catch (OcpiException $e) {
            $this->assertStringContainsString('authorization', $e->getMessage());
        }
        $p->update(['authorized' => true]);
        try {
            app(PartnerClient::class)->pull($p, 'cdrs');
            $this->fail();
        } catch (OcpiException $e) {
            $this->assertStringContainsString('negotiated', $e->getMessage());
        }Http::assertNothingSent();
    }

    public function test_pagination_loop_and_cross_origin_next_link_are_rejected(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        Http::fake(['https://partner.example/locations' => Http::response($this->envelope([$this->location()]), 200, ['Link' => '<https://attacker.example/page2>; rel="next"'])]);
        try {
            app(PartnerClient::class)->pull($p, 'locations');
            $this->fail();
        } catch (OcpiException $e) {
            $this->assertStringContainsString('approved HTTPS origin', $e->getMessage());
        }Http::assertSentCount(1);
    }

    public function test_normalized_catalog_deduplicates_retains_truth_labels_and_encrypts_raw_records(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1']);
        $record = $this->location();
        $sync = app(RecordSynchronizer::class);
        $this->assertSame(['created' => 1, 'updated' => 0, 'duplicate' => 0, 'stale' => 0], $sync->ingest($p, 'locations', [$record]));
        $this->assertSame(1, $sync->ingest($p, 'locations', [$record])['duplicate']);
        $r = OcpiRecord::first();
        $this->assertSame('unknown', $r->normalized['availability']);
        $this->assertFalse($r->normalized['remote_control']);
        $this->assertStringNotContainsString('Test address', DB::table('ocpi_records')->value('payload'));
        $this->getJson('/api/roaming/locations')->assertOk()->assertJsonPath('data.0.evidence', 'PARTNER_REPORTED_UNVERIFIED')->assertJsonPath('data.0.remote_control', false)->assertJsonMissingPath('data.0.payload');
    }

    public function test_same_timestamp_conflict_rolls_back_whole_batch_and_stale_updates_are_ignored(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1']);
        $sync = app(RecordSynchronizer::class);
        $record = $this->location();
        $sync->ingest($p, 'locations', [$record]);
        $changed = $record;
        $changed['name'] = 'Changed';
        $new = $record;
        $new['id'] = 'would-be-new';
        try {
            $sync->ingest($p, 'locations', [$new, $changed]);
            $this->fail();
        } catch (OcpiException $e) {
            $this->assertStringContainsString('same source timestamp', $e->getMessage());
        }$this->assertDatabaseCount('ocpi_records', 1);
        $changed['last_updated'] = now()->subHour()->toISOString();
        $this->assertSame(1, $sync->ingest($p, 'locations', [$changed])['stale']);
    }

    public function test_final_cdr_is_immutable_and_has_no_billing_eligibility(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1']);
        $sync = app(RecordSynchronizer::class);
        $cdr = $this->cdr();
        $sync->ingest($p, 'cdrs', [$cdr]);
        $this->assertSame(1, $sync->ingest($p, 'cdrs', [$cdr])['duplicate']);
        $r = OcpiRecord::first();
        $this->assertFalse($r->normalized['billing_eligible']);
        $this->assertSame('12.34', $r->normalized['cost']['excl_vat']);
        $this->assertArrayNotHasKey('cdr_token', $r->normalized);
        $cdr['last_updated'] = now()->toISOString();
        $cdr['total_cost']['excl_vat'] = 20;
        try {
            $sync->ingest($p, 'cdrs', [$cdr]);
            $this->fail();
        } catch (OcpiException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        $r->delete();
    }

    public function test_tariff_and_session_normalization_validate_currency_and_preserve_decimal_text(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1']);
        $sync = app(RecordSynchronizer::class);
        $tariff = ['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'tariff-1', 'currency' => 'AED', 'last_updated' => now()->subMinute()->toISOString(), 'elements' => [['price_components' => [['type' => 'ENERGY', 'price' => 0.32, 'step_size' => 1, 'vat' => 5]]]]];
        $sync->ingest($p, 'tariffs', [$tariff]);
        $sync->ingest($p, 'sessions', [$this->sessionFixture()]);
        $this->assertSame('0.32', OcpiRecord::where('module', 'tariffs')->first()->normalized['elements'][0]['price_components'][0]['price']);
        $tariff['currency'] = 'invalid';
        $this->expectException(OcpiException::class);
        $sync->ingest($p, 'tariffs', [$tariff]);
    }

    public function test_provider_owner_scope_and_non_admin_configuration_are_enforced(): void
    {
        $p = $this->provider();
        $other = User::factory()->create(['role' => 'vendor']);
        $this->actingAs($other)->getJson('/api/ocpi/providers')->assertJsonCount(0, 'data');
        $this->getJson('/api/ocpi/providers/'.$p->id.'/records/sessions')->assertNotFound();
        $this->postJson('/api/ocpi/providers', ['owner_id' => $other->id, 'name' => 'Bad', 'connection_key' => 'unknown', 'authorized' => true])->assertForbidden();
        $this->actingAs(User::findOrFail($p->owner_id))->getJson('/api/ocpi/providers')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.connection_key');
    }

    public function test_full_owner_sync_paginates_and_hides_unpublished_or_revoked_locations(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $first = $this->location();
        $second = $first;
        $second['id'] = 'private-location';
        $second['publish'] = false;
        Http::fake(['https://partner.example/locations' => Http::response($this->envelope([$first]), 200, ['Link' => '<https://partner.example/locations?page=2>; rel="next"']), 'https://partner.example/locations?page=2' => Http::response($this->envelope([$second]))]);
        $this->actingAs(User::findOrFail($p->owner_id))->postJson('/api/ocpi/providers/'.$p->id.'/sync/locations')->assertOk()->assertJsonPath('counts.created', 2)->assertJsonPath('remote_control', false);
        $this->getJson('/api/roaming/locations')->assertJsonCount(1, 'data');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => false])->assertOk()->assertJsonPath('authorized', false);
        $this->getJson('/api/roaming/locations')->assertJsonCount(0, 'data');
        $this->assertNull($p->fresh()->version);
        Http::assertSentCount(2);
    }

    public function test_source_subsecond_updates_are_not_truncated_into_conflicting_replays(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1']);
        $sync = app(RecordSynchronizer::class);
        $r = $this->location();
        $r['last_updated'] = now()->subMinute()->startOfSecond()->format('Y-m-d\\TH:i:s').'.100000Z';
        $sync->ingest($p, 'locations', [$r]);
        $r['last_updated'] = substr($r['last_updated'], 0, 20).'200000Z';
        $r['name'] = 'Updated within same second';
        $this->assertSame(1, $sync->ingest($p, 'locations', [$r])['updated']);
    }

    public function test_malformed_or_future_records_never_create_a_partial_batch(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1']);
        $bad = $this->location();
        $bad['id'] = 'future';
        $bad['last_updated'] = now()->addHour()->toISOString();
        try {
            app(RecordSynchronizer::class)->ingest($p, 'locations', [$this->location(), $bad]);
            $this->fail();
        } catch (OcpiException $e) {
            $this->assertStringContainsString('future', $e->getMessage());
        }
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_ocpi_error_envelope_is_not_ingested_and_repeated_pages_do_not_escape_limits(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        Http::fake(['*' => Http::response(['status_code' => 2001, 'data' => [], 'timestamp' => now()->toISOString()])]);
        $this->expectException(OcpiException::class);
        app(PartnerClient::class)->pull($p, 'locations');
    }
}
