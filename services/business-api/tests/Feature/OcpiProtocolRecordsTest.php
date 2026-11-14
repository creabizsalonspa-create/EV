<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
