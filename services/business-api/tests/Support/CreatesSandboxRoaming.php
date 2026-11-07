<?php

namespace Tests\Support;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\RecordSynchronizer;
use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait CreatesSandboxRoaming
{
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
}
