<?php

namespace Tests\Feature;

use App\Http\Controllers\OcpiProvisioningController;
use App\Models\OcpiProvider;
use App\Services\Ocpi\PartnerClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function connection(): void
    {
        config(['ocpi.connections' => [
            'valid-connection' => ['origin' => 'https://partner.example', 'authorization_token' => 'PRIVATE-SYNTHETIC-TOKEN'],
            'missing-token' => ['origin' => 'https://partner.example'],
            'invalid-origin' => ['origin' => 'http://127.0.0.1', 'authorization_token' => 'PRIVATE-SYNTHETIC-TOKEN'],
            'assigned-connection' => ['origin' => 'https://partner.example', 'authorization_token' => 'PRIVATE-SYNTHETIC-TOKEN'],
        ]]);
    }

    public function test_only_admin_can_read_connection_and_vendor_selectors_without_secrets_or_external_requests(): void
    {
        $this->connection();
        $vendor = User::factory()->create(['role' => 'vendor']);
        OcpiProvider::create(['owner_id' => $vendor->id, 'name' => 'Existing provider', 'connection_key' => 'assigned-connection']);
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/ocpi/connection-options')->assertForbidden();
            $this->getJson('/api/ocpi/owner-options')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->getJson('/api/ocpi/connection-options')->assertOk()
            ->assertJsonPath('data.0.configuration_valid', true)->assertJsonPath('data.0.assigned', false)
            ->assertJsonPath('data.1.configuration_valid', false)->assertJsonPath('data.2.configuration_valid', false)
            ->assertJsonPath('data.3.assigned', true)->assertJsonPath('external_requests_made', false)->assertJsonPath('partner_acceptance_verified', false);
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC-TOKEN', $response->getContent());
        $this->assertStringNotContainsString('partner.example', $response->getContent());
        $this->assertStringNotContainsString('authorization_token', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_vendor_selector_is_paginated_searchable_and_excludes_other_roles_and_private_fields(): void
    {
        User::factory()->count(26)->create(['role' => 'vendor', 'name' => 'Scoped vendor']);
        User::factory()->create(['name' => 'Scoped customer']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $page = $this->getJson('/api/ocpi/owner-options?q=Scoped')->assertOk()->assertJsonPath('total', 26)->assertJsonCount(25, 'data');
        $this->assertSame(['id', 'name'], array_keys($page->json('data.0')));
        $this->getJson('/api/ocpi/owner-options?q=Scoped&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/ocpi/owner-options?q=customer')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/ocpi/owner-options?q='.str_repeat('x', 81))->assertUnprocessable();
    }

    public function test_owner_selector_only_returns_active_vendor_literal_matches_on_both_mounts(): void
    {
        $active = User::factory()->create(['role' => 'vendor', 'account_status' => 'active', 'name' => 'Literal %_! owner']);
        User::factory()->create(['role' => 'vendor', 'account_status' => 'active', 'name' => 'Literal ABC owner']);
        User::factory()->create(['role' => 'customer', 'name' => 'Literal %_! customer']);
        $inactive = User::factory()->create(['role' => 'vendor', 'name' => 'Literal %_! inactive']);
        DB::table('users')->where('id', $inactive->id)->update(['account_status' => 'suspended']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['/api/ocpi', '/api/v1/ocpi'] as $mount) {
            $this->getJson($mount.'/owner-options?q='.rawurlencode('%_!'))->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $active->id)
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->getJson($mount.'/owner-options?page=100001')->assertUnprocessable();
            $this->getJson($mount.'/owner-options?page=0')->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public function test_captured_admin_request_cannot_read_private_onboarding_after_authority_changes(): void
    {
        $this->connection();
        $controller = app(OcpiProvisioningController::class);
        foreach ([
            ['account_status' => 'suspended', 'expected' => 403],
            ['role' => 'vendor', 'expected' => 403],
            ['auth_epoch' => 21, 'expected' => 409],
            ['password' => 'changed-hash-fixture', 'expected' => 409],
        ] as $change) {
            $admin = User::factory()->create(['role' => 'admin', 'auth_epoch' => 20]);
            $request = Request::create('/api/ocpi/owner-options');
            $request->setUserResolver(fn () => $admin);
            $expected = $change['expected'];
            unset($change['expected']);
            DB::table('users')->where('id', $admin->id)->update($change);
            foreach ([
                fn () => $controller->owners($request),
                fn () => $controller->connections($request, app(PartnerClient::class)),
            ] as $read) {
                try {
                    $read();
                    $this->fail('Retired admin read should be rejected.');
                } catch (HttpExceptionInterface $e) {
                    $this->assertSame($expected, $e->getStatusCode());
                }
            }
        }
        Http::assertNothingSent();
    }

    public function test_admin_can_register_only_server_configured_connections_and_role_valid_owner_without_authorizing_partner(): void
    {
        $this->connection();
        $vendor = User::factory()->create(['role' => 'vendor']);
        $customer = User::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $body = ['owner_id' => $vendor->id, 'name' => 'Literal <provider>', 'connection_key' => 'valid-connection', 'authorized' => false];
        $this->postJson('/api/ocpi/providers', array_replace($body, ['owner_id' => $customer->id]))->assertUnprocessable();
        $this->postJson('/api/ocpi/providers', array_replace($body, ['connection_key' => 'unconfigured']))->assertUnprocessable();
        $created = $this->postJson('/api/ocpi/providers', $body)->assertCreated()->assertJsonPath('authorized', false)->assertJsonMissingPath('connection_key')->assertJsonMissingPath('endpoints');
        $this->getJson('/api/ocpi/connection-options')->assertJsonPath('data.0.assigned', true);
        $this->postJson('/api/ocpi/providers', $body)->assertUnprocessable();
        $this->assertDatabaseCount('ocpi_providers', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'ocpi.provider_created', 'subject' => (string) $created->json('id')]);
        Http::assertNothingSent();
    }
}
