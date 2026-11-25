<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiProtocolRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        config(['ocpi.connections.receiver' => ['managed_credentials' => true, 'incoming_modules' => ['sessions', 'cdrs'], 'origin' => 'https://partner.example', 'authorization_token' => base64_encode('SYNTHETIC-BOOTSTRAP'), 'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'CPO'],
            'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic EMSP']]]]]);
        $provider = OcpiProvider::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Approved CPO fixture', 'connection_key' => 'receiver', 'authorized' => true, 'version' => '2.2.1', 'endpoints' => ['sessions' => 'https://partner.example/sessions', 'cdrs' => 'https://partner.example/cdrs']]);
        $token = bin2hex(random_bytes(32));
        DB::table('ocpi_credential_links')->insert(['provider_id' => $provider->id, 'owner_id' => $provider->owner_id, 'generation' => 1, 'state' => 'active', 'configuration_hash' => app(CredentialExchange::class)->configurationHash($provider), 'bootstrap_hash' => hash('sha256', 'SYNTHETIC-BOOTSTRAP'), 'local_token_digest' => hash('sha256', $token), 'local_token_ciphertext' => Crypt::encryptString($token), 'remote_token_ciphertext' => Crypt::encryptString('SYNTHETIC-PEER'), 'created_at' => now(), 'updated_at' => now()]);

        return [$provider, ['Authorization' => 'Token '.base64_encode($token)]];
    }

    private function sessionPayload(): array
    {
        return ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'session-1', 'last_updated' => now()->subMinutes(5)->toISOString(), 'start_date_time' => now()->subHours(2)->toISOString(), 'end_date_time' => now()->subHour()->toISOString(), 'currency' => 'EUR', 'status' => 'COMPLETED', 'kwh' => '12.34', 'location_id' => 'location-1', 'evse_uid' => 'evse-1', 'connector_id' => '1', 'total_cost' => ['excl_vat' => '6.17']];
    }

    private function cdr(array $s): array
    {
        return ['country_code' => 'NL', 'party_id' => 'ABC', 'id' => 'cdr-1', 'session_id' => $s['id'], 'last_updated' => $s['last_updated'], 'start_date_time' => $s['start_date_time'], 'end_date_time' => $s['end_date_time'], 'currency' => $s['currency'], 'total_energy' => $s['kwh'], 'total_time' => '1', 'total_cost' => $s['total_cost'], 'cdr_location' => ['id' => $s['location_id'], 'evse_uid' => $s['evse_uid'], 'connector_id' => $s['connector_id']], 'cdr_token' => ['uid' => 'PRIVATE-SOURCE-TAG']];
    }

    public function test_managed_pull_rejects_foreign_party_in_any_page_without_partial_ingestion(): void
    {
        [$provider] = $this->context();
        $session = $this->sessionPayload();
        $owner = User::findOrFail($provider->owner_id);
        Http::preventStrayRequests();
        Http::fake([
            'https://partner.example/sessions' => Http::response([
                'status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => [$session],
            ], 200, ['Link' => '<https://partner.example/sessions?page=2>; rel="next"']),
            'https://partner.example/sessions?page=2' => Http::response([
                'status_code' => 1000, 'timestamp' => now()->toISOString(),
                'data' => [array_replace($session, ['party_id' => 'BAD', 'id' => 'foreign-session'])],
            ]),
        ]);
        $this->actingAs($owner)->postJson('/api/ocpi/providers/'.$provider->id.'/sync/sessions')->assertConflict();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('ocpi_session_meter_history', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertNull($provider->fresh()->last_synced_at);
    }

    public function test_managed_cdr_pull_rejects_foreign_country_and_preserves_accepted_session(): void
    {
        [$provider, $headers] = $this->context();
        $session = $this->sessionPayload();
        $this->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/session-1', $session, $headers)->assertCreated();
        Http::preventStrayRequests();
        $cdr = $this->cdr($session);
        Http::fake([
            'https://partner.example/cdrs' => Http::response([
                'status_code' => 1000, 'timestamp' => now()->toISOString(),
                'data' => [$cdr, array_replace($cdr, ['country_code' => 'AE', 'id' => 'foreign-cdr'])],
            ]),
        ]);
        $this->actingAs(User::findOrFail($provider->owner_id))->withHeaders(['Authorization' => ''])
            ->postJson('/api/ocpi/providers/'.$provider->id.'/sync/cdrs')->assertConflict();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertSame('sessions', OcpiRecord::firstOrFail()->module);
        $this->assertDatabaseCount('ocpi_record_receipts', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_corrupted_stored_session_cannot_be_returned_patched_or_acknowledged_as_duplicate(): void
    {
        [, $headers] = $this->context();
        $path = '/api/roaming-protocol/2.2.1/sessions/NL/ABC/session-1';
        $session = array_replace($this->sessionPayload(), ['status' => 'ACTIVE', 'end_date_time' => null]);
        $this->putJson($path, $session, $headers)->assertCreated();
        $record = OcpiRecord::firstOrFail();
        DB::table('ocpi_records')->where('id', $record->id)->update([
            'normalized' => json_encode(array_replace($record->normalized, ['energy_kwh' => '99']), JSON_THROW_ON_ERROR),
        ]);
        $this->getJson($path, $headers)->assertConflict()->assertJsonPath('data', null);
        $this->patchJson($path, ['last_updated' => now()->subMinute()->toISOString(), 'kwh' => '15'], $headers)->assertConflict();
        $this->putJson($path, $session, $headers)->assertConflict();
        $this->assertDatabaseCount('ocpi_record_receipts', 1);
        $this->assertSame($record->content_hash, $record->fresh()->content_hash);
        DB::table('ocpi_records')->where('id', $record->id)->update(['normalized' => $record->getRawOriginal('normalized')]);
        $this->getJson($path, $headers)->assertOk()->assertJsonPath('data.kwh', '12.34');
        $this->patchJson($path, ['last_updated' => now()->subMinute()->toISOString(), 'kwh' => '15'], $headers)->assertOk();
        $this->assertDatabaseCount('ocpi_record_receipts', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_corrupted_cdr_cannot_be_returned_or_acknowledged_by_exact_replay(): void
    {
        [, $headers] = $this->context();
        $path = '/api/roaming-protocol/2.2.1/cdrs';
        $cdr = $this->cdr($this->sessionPayload());
        $this->postJson($path, $cdr, $headers)->assertCreated();
        $record = OcpiRecord::firstOrFail();
        DB::table('ocpi_records')->where('id', $record->id)->update([
            'payload' => Crypt::encryptString(json_encode(array_replace($cdr, ['total_energy' => '99']), JSON_THROW_ON_ERROR)),
        ]);
        $this->getJson($path.'/cdr-1', $headers)->assertConflict()->assertJsonPath('data', null);
        $this->postJson($path, $cdr, $headers)->assertConflict();
        $this->assertDatabaseCount('ocpi_record_receipts', 1);
        DB::table('ocpi_records')->where('id', $record->id)->update(['payload' => $record->getRawOriginal('payload')]);
        $this->postJson($path, $cdr, $headers)->assertOk();
        $this->getJson($path.'/cdr-1', $headers)->assertOk()->assertJsonPath('data.total_energy', '12.34');
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_exact_numeric_zero_fraction_survives_encrypted_source_and_receipt_replay(): void
    {
        [$provider, $headers] = $this->context();
        $session = array_replace($this->sessionPayload(), ['kwh' => 12.0]);
        $path = '/api/roaming-protocol/2.2.1/sessions/NL/ABC/session-1';
        $this->call('PUT', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
        ], json_encode($session, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->assertCreated();
        $record = OcpiRecord::where('ocpi_provider_id', $provider->id)->firstOrFail();
        $this->assertSame(12.0, $record->payload['kwh']);
        $this->assertSame('12', $record->normalized['energy_kwh']);
        $this->assertSame($record->content_hash, app(RecordNormalizer::class)->hash($record->payload));
        $this->call('PUT', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
        ], json_encode($session, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->assertOk();
        $this->getJson($path, $headers)->assertOk();
        $this->assertDatabaseCount('ocpi_record_receipts', 1);
        $this->assertDatabaseCount('ocpi_session_meter_history', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_managed_sync_cannot_acknowledge_or_replace_corrupt_existing_source(): void
    {
        [$provider, $headers] = $this->context();
        $session = array_replace($this->sessionPayload(), ['status' => 'ACTIVE', 'end_date_time' => null]);
        $this->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/session-1', $session, $headers)->assertCreated();
        $record = OcpiRecord::firstOrFail();
        DB::table('ocpi_records')->where('id', $record->id)->update(['content_hash' => str_repeat('a', 64)]);
        $before = DB::table('ocpi_records')->where('id', $record->id)->first();
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(),
            'data' => [array_replace($session, ['id' => 'candidate']), $session]]));
        $url = '/api/ocpi/providers/'.$provider->id.'/sync/sessions';
        $this->actingAs(User::findOrFail($provider->owner_id))->withHeaders(['Authorization' => ''])->postJson($url)->assertConflict();
        Http::assertSentCount(1);
        $this->assertEquals($before, DB::table('ocpi_records')->where('id', $record->id)->first());
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('ocpi_session_meter_history', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        DB::table('ocpi_records')->where('id', $record->id)->update(['content_hash' => $record->content_hash]);
        $this->postJson($url)->assertOk()->assertJsonPath('counts.created', 1)->assertJsonPath('counts.duplicate', 1);
        $this->assertDatabaseCount('ocpi_records', 2);
        $this->assertDatabaseCount('ocpi_session_meter_history', 2);
    }

    public function test_authenticated_session_and_cdr_delivery_is_idempotent_and_visible_to_owned_operations(): void
    {
        [$provider, $headers] = $this->context();
        $session = $this->sessionPayload();
        $path = '/api/roaming-protocol/2.2.1';
        $this->putJson($path.'/sessions/NL/ABC/session-1', $session, $headers)->assertCreated()->assertJsonPath('status_code', 1000);
        $this->putJson($path.'/sessions/NL/ABC/session-1', $session, $headers)->assertOk();
        $cdr = $this->cdr($session);
        $this->postJson($path.'/cdrs', $cdr, $headers)->assertCreated()->assertHeader('Location', 'https://platform.example/api/roaming-protocol/2.2.1/cdrs/cdr-1');
        $this->postJson($path.'/cdrs', $cdr, $headers)->assertOk();
        $this->getJson($path.'/cdrs/cdr-1', $headers)->assertOk()->assertJsonPath('data.id', 'cdr-1')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::findOrFail($provider->owner_id))->getJson('/api/ocpi/providers/'.$provider->id.'/records/cdrs')->assertOk()->assertJsonPath('data.0.reconciliation.status', 'source_consistent')->assertDontSee('PRIVATE-SOURCE-TAG')->assertJsonPath('data.0.reconciliation.billing_eligible', false);
        $this->assertDatabaseCount('ocpi_record_receipts', 2);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->postJson($path.'/cdrs', array_replace($cdr, ['total_energy' => '50']), $headers)->assertConflict();
        $this->assertSame('12.34', OcpiRecord::where('module', 'cdrs')->firstOrFail()->normalized['energy_kwh']);
    }

    public function test_partial_sessions_preserve_fields_but_stale_and_completed_rollback_are_rejected(): void
    {
        [, $headers] = $this->context();
        $session = array_replace($this->sessionPayload(), ['status' => 'ACTIVE', 'end_date_time' => null, 'kwh' => '0']);
        $path = '/api/roaming-protocol/2.2.1/sessions/NL/ABC/session-1';
        $this->putJson($path, $session, $headers)->assertCreated();
        $this->patchJson($path, ['last_updated' => now()->subMinutes(3)->toISOString(), 'kwh' => '12.34', 'total_cost' => ['incl_vat' => '6.50']], $headers)->assertOk();
        $this->getJson($path, $headers)->assertJsonPath('data.total_cost.excl_vat', '6.17')->assertJsonPath('data.total_cost.incl_vat', '6.50');
        $this->patchJson($path, ['last_updated' => now()->subMinutes(4)->toISOString(), 'kwh' => '1'], $headers)->assertConflict();
        $this->patchJson($path, ['last_updated' => now()->subMinutes(2)->toISOString(), 'status' => 'COMPLETED', 'end_date_time' => now()->subHour()->toISOString()], $headers)->assertOk();
        $this->patchJson($path, ['last_updated' => now()->subMinute()->toISOString(), 'status' => 'ACTIVE'], $headers)->assertConflict();
        $this->patchJson($path, ['kwh' => '99'], $headers)->assertBadRequest();
        $this->assertDatabaseCount('ocpi_record_receipts', 3);
    }

    public function test_cookie_bootstrap_provisional_revoked_and_cross_party_authority_cannot_push_records(): void
    {
        [$provider, $headers] = $this->context();
        $path = '/api/roaming-protocol/2.2.1/sessions/NL/ABC/session-1';
        $session = $this->sessionPayload();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson($path, $session)->assertUnauthorized();
        $this->putJson($path, $session, ['Authorization' => 'Token '.base64_encode('SYNTHETIC-BOOTSTRAP')])->assertUnauthorized();
        $this->putJson($path, array_replace($session, ['party_id' => 'BAD']), $headers)->assertForbidden();
        $this->putJson(str_replace('/NL/', '/AE/', $path), $session, $headers)->assertForbidden();
        foreach (['dispatching', 'awaiting_registration', 'revoked'] as $state) {
            DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update(['state' => $state]);
            $this->putJson($path, $session, $headers)->assertUnauthorized();
        }
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('ocpi_record_receipts', 0);
    }

    public function test_suspending_owner_preserves_authenticated_historical_session_and_cdr_evidence(): void
    {
        [$provider, $headers] = $this->context();
        $session = $this->sessionPayload();
        $path = '/api/roaming-protocol/2.2.1';
        $this->putJson($path.'/sessions/NL/ABC/session-1', $session, $headers)->assertCreated();
        User::findOrFail($provider->owner_id)->forceFill(['account_status' => 'suspended'])->save();
        $this->postJson($path.'/cdrs', $this->cdr($session), $headers)->assertCreated();
        $this->getJson($path.'/cdrs/cdr-1', $headers)->assertOk();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_receiver_capabilities_are_opt_in_pinned_to_credential_scope_and_discovered(): void
    {
        [$provider, $headers] = $this->context();
        $this->getJson('/api/roaming-protocol/2.2.1', $headers)->assertOk()->assertJsonCount(3, 'data.endpoints')->assertJsonPath('data.endpoints.1.identifier', 'cdrs');
        config(['ocpi.connections.receiver.incoming_modules' => []]);
        $this->putJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/session-1', $this->sessionPayload(), $headers)->assertUnauthorized();
        $this->assertDatabaseCount('ocpi_records', 0);
    }
}
