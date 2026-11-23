<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiProviderCapabilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config([
            'ocpi.local_credentials' => [
                'url' => 'https://platform.example/api/roaming-protocol/versions',
                'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX',
                    'business_details' => ['name' => 'Synthetic eMSP']]],
            ],
            'ocpi.connections.capability-test' => [
                'managed_credentials' => true, 'origin' => 'https://peer.example',
                'authorization_token' => base64_encode('SYNTHETIC-PEER-BOOTSTRAP'),
                'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'CPO',
                'incoming_modules' => ['locations', 'sessions', 'cdrs'], 'environment' => 'sandbox',
            ],
        ]);
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'connection_key' => 'capability-test', 'name' => 'Private synthetic peer',
            'authorized' => true, 'version' => '2.2.1',
            'endpoints' => ['locations' => 'https://peer.example/locations', 'tariffs' => 'https://peer.example/tariffs'],
        ]);
        $token = 'SYNTHETIC-ACTIVE-LOCAL-TOKEN';
        DB::table('ocpi_credential_links')->insert([
            'provider_id' => $provider->id, 'owner_id' => $owner->id, 'generation' => 1,
            'state' => 'active', 'configuration_hash' => app(CredentialExchange::class)->configurationHash($provider),
            'bootstrap_hash' => hash('sha256', base64_encode('SYNTHETIC-PEER-BOOTSTRAP')),
            'local_token_digest' => hash('sha256', $token), 'local_token_ciphertext' => Crypt::encryptString($token),
            'remote_token_ciphertext' => Crypt::encryptString('SYNTHETIC-REMOTE-TOKEN'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $admin, $provider, '/api/ocpi/providers/'.$provider->id.'/credentials'];
    }

    public function test_customer_vendor_and_admin_status_keeps_source_only_capabilities_with_owner_revocation_fences(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson($path)->assertNotFound();
        $status = $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('capability_matrix.evidence', 'NEGOTIATED_AND_CONFIGURED_SOFTWARE_ONLY')
            ->assertJsonPath('capability_matrix.peer_role', 'CPO')
            ->assertJsonPath('capability_matrix.credentials_current', true)
            ->assertJsonPath('capability_matrix.modules.0.module', 'locations')
            ->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', true)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.1.module', 'tariffs')
            ->assertJsonPath('capability_matrix.modules.1.partner_sender_negotiated', true)
            ->assertJsonPath('capability_matrix.modules.1.local_receiver_active', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.0.local_sender_active', false)
            ->assertJsonPath('capability_matrix.modules.0.sandbox_delivery_software_enabled', false)
            ->assertJsonPath('capability_matrix.live_roaming_verified', false)
            ->assertJsonPath('capability_matrix.physical_availability_verified', false)
            ->assertJsonPath('capability_matrix.partner_conformance_verified', false)
            ->assertJsonPath('capability_matrix.payment_authority', false)
            ->assertDontSee('SYNTHETIC-ACTIVE-LOCAL-TOKEN')
            ->assertDontSee('https://peer.example');
        $this->assertCount(4, $status->json('capability_matrix.modules'));

        $owner->forceFill(['account_status' => 'suspended'])->save();
        $this->actingAs($admin)->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', false)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_active', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.0.local_sender_active', false);
        $provider->update(['authorized' => false]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_active', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_cpo_sender_requires_current_generation_and_sandbox_delivery_remains_nonlive(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        config([
            'ocpi.local_credential_profiles.catalog' => [
                'url' => 'https://platform.example/api/roaming-protocol/versions',
                'roles' => [['role' => 'CPO', 'country_code' => 'AE', 'party_id' => 'EVX',
                    'business_details' => ['name' => 'Synthetic CPO']]],
            ],
            'ocpi.connections.capability-test.party_role' => 'EMSP',
            'ocpi.connections.capability-test.incoming_modules' => [],
            'ocpi.connections.capability-test.local_credential_profile' => 'catalog',
            'ocpi.connections.capability-test.cpo_catalog' => ['id' => 'cpo-fixture', 'sites' => [], 'tariffs' => []],
            'ocpi.cpo_push_enabled' => true,
        ]);
        $provider->update(['endpoints' => [
            'locations_receiver' => 'https://peer.example/locations',
            'tariffs_receiver' => 'https://peer.example/tariffs',
        ]]);
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)
            ->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $this->actingAs($admin)->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.peer_role', 'EMSP')
            ->assertJsonPath('capability_matrix.modules.0.local_sender_configured', true)
            ->assertJsonPath('capability_matrix.modules.0.local_sender_active', true)
            ->assertJsonPath('capability_matrix.modules.0.sandbox_delivery_software_enabled', true)
            ->assertJsonPath('capability_matrix.modules.1.local_sender_active', true)
            ->assertJsonPath('capability_matrix.modules.2.local_sender_configured', false)
            ->assertJsonPath('capability_matrix.live_roaming_verified', false);
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->update(['generation' => 2, 'state' => 'revoked']);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('capability_matrix.modules.0.local_sender_configured', true)
            ->assertJsonPath('capability_matrix.modules.0.local_sender_active', false)
            ->assertJsonPath('capability_matrix.modules.0.sandbox_delivery_software_enabled', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }
}
