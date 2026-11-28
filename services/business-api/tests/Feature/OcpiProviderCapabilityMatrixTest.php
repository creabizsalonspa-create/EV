<?php

namespace Tests\Feature;

use App\Http\Controllers\OcpiCredentialsController;
use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_sandbox_policy_capabilities_are_read_only_and_require_current_approved_contracts(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_token_sender_configured', false)
            ->assertJsonPath('capability_matrix.sandbox_token_sender_request_ready', false)
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', false)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', false);
        config([
            'ocpi.test_commands_enabled' => true,
            'ocpi.connections.capability-test.test_commands' => true,
            'ocpi.connections.capability-test.test_command_contract' => 'synthetic-command-contract',
        ]);
        $provider->update(['endpoints' => [
            'locations' => 'https://peer.example/locations',
            'tariffs' => 'https://peer.example/tariffs',
            'sessions' => 'https://peer.example/sessions',
            'cdrs' => 'https://peer.example/cdrs',
            'tokens_receiver' => 'https://peer.example/tokens',
            'commands_receiver' => 'https://peer.example/commands',
        ]]);
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', false);
        $link->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_token_sender_configured', true)
            ->assertJsonPath('capability_matrix.sandbox_token_sender_request_ready', true)
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', true)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', false)
            ->assertJsonPath('charging_commands_enabled', false);
        config([
            'ocpi.connections.capability-test.test_reservations' => true,
            'ocpi.connections.capability-test.test_reservation_contract' => 'synthetic-reservation-contract',
        ]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', false)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', false);
        $link->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', true)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', true)
            ->assertJsonPath('capability_matrix.live_roaming_verified', false)
            ->assertJsonPath('billing_eligible', false)
            ->assertDontSee('synthetic-reservation-contract');
        config(['ocpi.connections.capability-test.read_modules' => ['locations', 'tariffs', 'sessions']]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_token_sender_request_ready', true)
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', false)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', false)
            ->assertJsonPath('capability_matrix.modules.3.partner_sender_negotiated', false)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_active', true);
        config(['ocpi.connections.capability-test.read_modules' => ['cdrs', 'sessions', 'tariffs', 'locations']]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', true)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', true);
        $endpoints = $provider->endpoints;
        $provider->update(['endpoints' => array_replace($endpoints, ['commands_receiver' => 'https://foreign.example/commands'])]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_token_sender_configured', true)
            ->assertJsonPath('capability_matrix.sandbox_token_sender_request_ready', false)
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', false)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', false);
        $provider->update(['endpoints' => $endpoints]);
        $owner->forceFill(['account_status' => 'suspended'])->save();
        $this->actingAs($admin)->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.sandbox_command_policy_ready', false)
            ->assertJsonPath('capability_matrix.sandbox_reservation_policy_ready', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
        $this->assertDatabaseCount('ocpi_roaming_orders', 0);
        $this->assertDatabaseCount('ocpi_roaming_commands', 0);
    }

    public function test_peer_token_module_is_advertised_only_for_a_configured_sandbox_contract(): void
    {
        [, , $provider] = $this->fixture();
        $headers = ['Authorization' => 'Token '.base64_encode('SYNTHETIC-ACTIVE-LOCAL-TOKEN')];
        $url = '/api/roaming-protocol/2.2.1';
        $tokenEndpoint = ['identifier' => 'tokens', 'role' => 'SENDER',
            'url' => 'https://platform.example/api/roaming-protocol/2.2.1/tokens'];
        config([
            'ocpi.test_commands_enabled' => true,
            'ocpi.connections.capability-test.test_commands' => true,
            'ocpi.connections.capability-test.test_command_contract' => 'synthetic-command-contract',
        ]);
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id);
        $link->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $provider->update(['version' => null, 'endpoints' => null]);
        $this->getJson($url, $headers)->assertOk()->assertJsonFragment($tokenEndpoint);
        $this->getJson($url.'/tokens', $headers)->assertConflict();

        config(['ocpi.connections.capability-test.environment' => 'production']);
        $this->getJson($url, $headers)->assertOk()->assertJsonMissing($tokenEndpoint);
        config([
            'ocpi.connections.capability-test.environment' => 'sandbox',
            'ocpi.connections.capability-test.incoming_modules' => ['locations', 'sessions'],
        ]);
        $link->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $this->getJson($url, $headers)->assertOk()->assertJsonMissing($tokenEndpoint);
        Http::assertNothingSent();
    }

    public function test_network_read_scope_restricts_sender_evidence_without_granting_receiver_authority(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        config(['ocpi.connections.capability-test.read_modules' => ['tariffs']]);
        $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', false)
            ->assertJsonPath('capability_matrix.modules.1.partner_sender_negotiated', true)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.1.local_receiver_active', false)
            ->assertJsonPath('capability_matrix.live_roaming_verified', false)
            ->assertJsonPath('capability_matrix.payment_authority', false);
        $this->assertSame(['tariffs'], $provider->fresh()->readable_modules);
        config(['ocpi.connections.capability-test.read_modules' => []]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', false)
            ->assertJsonPath('capability_matrix.modules.1.partner_sender_negotiated', false)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_active', true);
        $this->assertSame([], $provider->fresh()->readable_modules);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
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

    public function test_status_denies_revoked_vendor_snapshot_and_reassigned_provider_without_exposing_credentials(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $captured = Request::create($path, 'GET');
        $captured->setUserResolver(static fn () => $owner);
        $controller = app(OcpiCredentialsController::class);
        $exchange = app(CredentialExchange::class);
        $current = $controller->status($captured, $provider, $exchange)->getData(true);
        $this->assertTrue($current['capability_matrix']['credentials_current']);
        $this->assertFalse($current['capability_matrix']['live_roaming_verified']);

        $initial = [
            'role' => 'vendor', 'account_status' => 'active',
            'auth_epoch' => $owner->auth_epoch, 'password' => $owner->password,
        ];
        foreach ([
            [['account_status' => 'suspended'], 403],
            [['role' => 'customer'], 403],
            [['auth_epoch' => (int) $owner->auth_epoch + 1], 409],
            [['password' => 'rotated-test-password-hash'], 409],
        ] as [$change, $expected]) {
            DB::table('users')->where('id', $owner->id)->update(array_replace($initial, $change));
            try {
                $controller->status($captured, $provider, $exchange);
                $this->fail('An obsolete authenticated vendor read private OCPI credentials.');
            } catch (HttpException $error) {
                $this->assertSame($expected, $error->getStatusCode());
            }
        }
        DB::table('users')->where('id', $owner->id)->update($initial);

        $replacement = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        DB::table('ocpi_providers')->where('id', $provider->id)->update([
            'owner_id' => $replacement->id,
        ]);
        $adminRequest = Request::create($path, 'GET');
        $adminRequest->setUserResolver(static fn () => $admin);
        foreach ([$captured, $adminRequest] as $staleRequest) {
            try {
                $controller->status($staleRequest, $provider, $exchange);
                $this->fail('Old ownership leaked private credentials or capability evidence.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('ocpi_credential_links', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
        Http::assertNothingSent();
    }

    public function test_incomplete_active_token_link_cannot_advertise_private_module_authority(): void
    {
        [, $admin, $provider, $path] = $this->fixture();
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id);
        $link->update(['local_token_ciphertext' => null]);
        $this->actingAs($admin)->getJson($path)->assertOk()
            ->assertJsonPath('state', 'active')
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('available_actions', ['unregister'])
            ->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', false)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_configured', true)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_active', false)
            ->assertJsonPath('active_receiver_modules', []);
        $link->update(['local_token_ciphertext' => Crypt::encryptString('SYNTHETIC-RESTORED')]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_active', false);
        $link->update(['local_token_ciphertext' => Crypt::encryptString('SYNTHETIC-ACTIVE-LOCAL-TOKEN'),
            'remote_token_ciphertext' => null]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_active', false)
            ->assertJsonPath('capability_matrix.live_roaming_verified', false);
        $link->update(['remote_token_ciphertext' => Crypt::encryptString('SYNTHETIC-REMOTE-TOKEN'),
            'local_token_ciphertext' => Crypt::encryptString('SYNTHETIC-ACTIVE-LOCAL-TOKEN')]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', true)
            ->assertJsonPath('available_actions', ['rotate', 'unregister']);
        $link->update(['remote_token_ciphertext' => 'not-laravel-ciphertext']);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('available_actions', ['unregister'])
            ->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_credential_links', 1);
    }

    public function test_credential_action_evidence_respects_owner_authorization_and_lifecycle_exhaustion(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id);
        $this->actingAs($admin)->getJson($path)->assertOk()
            ->assertJsonPath('available_actions', ['rotate', 'unregister']);
        $link->update(['generation' => 2147483647]);
        $this->getJson($path)->assertOk()->assertJsonPath('available_actions', ['unregister']);
        $link->update(['generation' => 1]);
        $provider->update(['authorization_revision' => PHP_INT_MAX]);
        $this->getJson($path)->assertOk()->assertJsonPath('available_actions', ['unregister']);
        $provider->update(['authorization_revision' => 0]);
        $link->update(['local_token_ciphertext' => null]);
        $owner->forceFill(['role' => 'customer'])->save();
        $this->getJson($path)->assertOk()->assertJsonPath('available_actions', [])
            ->assertJsonPath('active_receiver_modules', []);
        $owner->forceFill(['role' => 'vendor'])->save();
        $provider->update(['authorized' => false]);
        $this->getJson($path)->assertOk()->assertJsonPath('available_actions', []);
        $provider->update(['authorized' => true]);
        $this->getJson($path)->assertOk()->assertJsonPath('available_actions', ['unregister']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
    }

    public function test_invalid_contract_configuration_returns_bounded_inactive_status_without_network(): void
    {
        [, $admin, , $path] = $this->fixture();
        $this->actingAs($admin)->getJson($path)->assertOk()
            ->assertJsonPath('configured', true);
        config(['ocpi.connections.capability-test.test_commands' => true,
            'ocpi.connections.capability-test.test_command_contract' => 'invalid private contract']);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('configured_receiver_modules', [])
            ->assertJsonPath('active_receiver_modules', [])
            ->assertJsonPath('available_actions', [])
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_request_ready', false)
            ->assertDontSee('invalid private contract');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_capability_matrix_does_not_advertise_unusable_or_foreign_negotiated_endpoints(): void
    {
        [, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin);
        foreach (['', 'http://peer.example/locations', 'https://foreign.example/locations',
            'https://peer.example:8443/locations', 'https://peer.example/locations#secret',
            '//foreign.example/locations'] as $invalid) {
            $provider->update(['endpoints' => ['locations' => $invalid, 'tariffs' => 'https://peer.example/tariffs']]);
            $this->getJson($path)->assertOk()
                ->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', false)
                ->assertJsonPath('capability_matrix.modules.1.partner_sender_negotiated', true)
                ->assertJsonPath('capability_matrix.live_roaming_verified', false)
                ->assertDontSee('https://peer.example');
        }
        $provider->update(['endpoints' => ['locations' => 'https://peer.example/locations']]);
        $this->getJson($path)->assertOk()->assertJsonPath('capability_matrix.modules.0.partner_sender_negotiated', true);
        Http::assertNothingSent();
    }

    public function test_receiver_request_readiness_distinguishes_negotiation_from_active_credentials(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin)->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_request_ready', true)
            ->assertJsonPath('capability_matrix.modules.1.local_receiver_request_ready', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_request_ready', false)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_request_ready', false);
        $provider->update(['version' => null]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_active', true)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_request_ready', false);
        $provider->update(['version' => '2.2.1', 'endpoints' => [
            'locations' => 'https://foreign.example/locations',
            'sessions' => 'https://peer.example/sessions',
            'cdrs' => 'https://peer.example/cdrs',
        ]]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_request_ready', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_request_ready', true)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_request_ready', true)
            ->assertDontSee('https://foreign.example');
        $owner->forceFill(['account_status' => 'suspended'])->save();
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.credentials_current', false)
            ->assertJsonPath('capability_matrix.modules.0.local_receiver_request_ready', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_request_ready', true)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_request_ready', true)
            ->assertJsonPath('capability_matrix.live_roaming_verified', false)
            ->assertJsonPath('capability_matrix.payment_authority', false);
        $owner->forceFill(['role' => 'customer'])->save();
        $this->getJson($path)->assertOk()
            ->assertJsonPath('active_receiver_modules', [])
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_active', false)
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_request_ready', false)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_request_ready', false);
        $owner->forceFill(['role' => 'vendor'])->save();
        $provider->update(['authorized' => false]);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('capability_matrix.modules.2.local_receiver_request_ready', false)
            ->assertJsonPath('capability_matrix.modules.3.local_receiver_request_ready', false);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
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
        foreach (['https://foreign.example/locations', 'https://peer.example/locations?secret=private'] as $invalidReceiver) {
            $provider->update(['endpoints' => [
                'locations_receiver' => $invalidReceiver,
                'tariffs_receiver' => 'https://peer.example/tariffs',
            ]]);
            $this->getJson($path)->assertOk()
                ->assertJsonPath('capability_matrix.modules.0.local_sender_active', true)
                ->assertJsonPath('capability_matrix.modules.0.sandbox_delivery_software_enabled', false)
                ->assertJsonPath('capability_matrix.modules.1.sandbox_delivery_software_enabled', true)
                ->assertDontSee('secret=private');
        }
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
