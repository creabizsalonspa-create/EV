<?php

namespace Tests\Feature;

use App\Http\Controllers\OcpiController;
use App\Http\Controllers\OcpiProvisioningController;
use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\PartnerBillingExport;
use App\Services\Ocpi\PartnerBillingReconciliation;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OcpiOwnerEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['ocpi.connections.owner-eligibility' => [
            'origin' => 'https://partner.example',
            'authorization_token' => base64_encode('synthetic-not-a-partner-token'),
            'environment' => 'sandbox',
        ]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function vendor(): User
    {
        return User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
    }

    private function pending(User $owner): OcpiProvider
    {
        return OcpiProvider::create([
            'owner_id' => $owner->id,
            'name' => 'Synthetic pending operator',
            'connection_key' => 'owner-eligibility',
            'authorized' => false,
        ]);
    }

    public function test_onboarding_selector_excludes_suspended_and_former_vendor_accounts(): void
    {
        $active = User::factory()->create(['role' => 'vendor', 'name' => 'Eligible Operator', 'account_status' => 'active']);
        User::factory()->create(['role' => 'vendor', 'name' => 'Suspended Operator', 'account_status' => 'suspended']);
        User::factory()->create(['role' => 'customer', 'name' => 'Customer Operator', 'account_status' => 'active']);

        $admin = $this->admin();
        $this->actingAs($admin);
        $page = $this->getJson('/api/ocpi/owner-options?q=Operator')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
        $this->assertSame(['id', 'name'], array_keys($page->json('data.0')));
        // Admin's personal access tokens cannot access native OCPI routes.
        // Only the private browser-authenticated Admin mount can provision.
        $token = $admin->createToken('synthetic-admin-denied')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/ocpi/owner-options?q=Operator')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_private_admin_onboarding_reads_recheck_current_role_epoch_and_password(): void
    {
        $admin = $this->admin();
        $this->vendor();
        $controller = app(OcpiProvisioningController::class);
        $snapshot = Request::create('/api/ocpi/owner-options', 'GET', ['q' => 'Agent']);
        $snapshot->setUserResolver(static fn () => $admin);
        $initial = [
            'role' => 'admin',
            'account_status' => 'active',
            'auth_epoch' => $admin->auth_epoch,
            'password' => $admin->password,
        ];

        foreach ([
            [['account_status' => 'suspended'], 403],
            [['role' => 'customer'], 403],
            [['auth_epoch' => (int) $admin->auth_epoch + 1], 409],
            [['password' => 'new-synthetic-hash'], 409],
        ] as [$change, $status]) {
            DB::table('users')->where('id', $admin->id)->update(array_replace($initial, $change));
            foreach ([
                static fn () => $controller->owners($snapshot),
                static fn () => $controller->connections($snapshot, app(PartnerClient::class)),
            ] as $read) {
                try {
                    $read();
                    $this->fail('Captured Admin identity accessed private OCPI onboarding after revocation.');
                } catch (HttpException $error) {
                    $this->assertSame($status, $error->getStatusCode());
                }
            }
        }
        DB::table('users')->where('id', $admin->id)->update($initial);
        $this->actingAs($admin);
        $this->getJson('/api/ocpi/owner-options')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/ocpi/connection-options')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_admin_cannot_register_pending_or_authorized_provider_for_suspended_or_former_owner(): void
    {
        $suspended = User::factory()->create(['role' => 'vendor', 'account_status' => 'suspended']);
        $former = User::factory()->create(['role' => 'customer', 'account_status' => 'active']);
        $this->actingAs($this->admin());

        foreach ([$suspended, $former] as $owner) {
            foreach ([false, true] as $authorize) {
                $this->postJson('/api/ocpi/providers', [
                    'owner_id' => $owner->id,
                    'name' => 'Ineligible owner',
                    'connection_key' => 'owner-eligibility',
                    'authorized' => $authorize,
                ])->assertUnprocessable();
            }
        }
        $this->assertDatabaseCount('ocpi_providers', 0);
        $this->assertDatabaseMissing('audit_events', ['action' => 'ocpi.provider_created']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_authorization_rechecks_current_vendor_eligibility_without_rewriting_revision_or_audit(): void
    {
        $owner = $this->vendor();
        $provider = $this->pending($owner);
        $this->actingAs($this->admin());

        foreach ([
            ['account_status' => 'suspended'],
            ['account_status' => 'active', 'role' => 'customer'],
        ] as $ineligible) {
            DB::table('users')->where('id', $owner->id)->update(['role' => 'vendor', 'account_status' => 'active']);
            DB::table('users')->where('id', $owner->id)->update($ineligible);
            $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization', ['authorized' => true])
                ->assertUnprocessable();
            $fresh = $provider->fresh();
            $this->assertFalse($fresh->authorized);
            $this->assertNull($fresh->version);
            $this->assertNull($fresh->endpoints);
            $this->assertSame(0, $fresh->authorization_revision);
        }

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'ocpi.provider_authorized', 'subject' => (string) $provider->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_provider_list_uses_current_tenant_and_rejects_stale_or_repurposed_vendor_identity(): void
    {
        $owner = $this->vendor();
        $peer = $this->vendor();
        $ownProvider = $this->pending($owner);
        $otherProvider = OcpiProvider::create([
            'owner_id' => $peer->id,
            'name' => 'Other private operator',
            'connection_key' => 'isolated-other-connection',
            'authorized' => false,
        ]);

        $request = Request::create('/api/ocpi/providers', 'GET');
        $request->setUserResolver(static fn () => $owner);
        $controller = app(OcpiController::class);
        $page = $controller->providers($request)->getData(true);
        $this->assertSame(1, $page['total']);
        $this->assertSame($ownProvider->id, $page['data'][0]['id']);
        $this->assertStringNotContainsString('Other private operator',
            json_encode($page, JSON_THROW_ON_ERROR));
        $this->assertNotSame($ownProvider->id, $otherProvider->id);

        $initial = [
            'role' => 'vendor',
            'account_status' => 'active',
            'auth_epoch' => $owner->auth_epoch,
            'password' => $owner->password,
        ];
        foreach ([
            [['account_status' => 'suspended'], 403],
            [['role' => 'customer'], 403],
            [['auth_epoch' => (int) $owner->auth_epoch + 1], 409],
            [['password' => 'rotated-synthetic-hash'], 409],
        ] as [$change, $expectedStatus]) {
            DB::table('users')->where('id', $owner->id)->update(array_replace($initial, $change));
            try {
                $controller->providers($request);
                $this->fail('Stale vendor identity disclosed private partner accounts.');
            } catch (HttpException $error) {
                $this->assertSame($expectedStatus, $error->getStatusCode());
            }
        }
        DB::table('users')->where('id', $owner->id)->update($initial);
        $this->actingAs($owner)->getJson('/api/ocpi/providers')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 1);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_providers', 2);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_private_remote_and_billing_endpoints_recheck_live_vendor_identity_before_external_or_finance_work(): void
    {
        $owner = $this->vendor();
        $other = $this->vendor();
        $provider = $this->pending($owner);
        $request = Request::create('/api/ocpi/providers/'.$provider->id, 'GET');
        $request->setUserResolver(static fn () => $owner);
        $controller = app(OcpiController::class);
        $remote = [
            'negotiate' => static fn () => $controller->negotiate($request, $provider, app(PartnerClient::class)),
            'sync' => static fn () => $controller->sync($request, $provider, 'locations',
                app(PartnerClient::class), app(RecordSynchronizer::class)),
        ];
        $billing = [
            'reconcile' => static fn () => $controller->billingReconciliation($request, $provider,
                app(PartnerBillingReconciliation::class)),
            'export' => static fn () => $controller->billingExport($request, $provider,
                app(PartnerBillingReconciliation::class), app(PartnerBillingExport::class), 'csv'),
        ];

        $initial = [
            'role' => 'vendor',
            'account_status' => 'active',
            'auth_epoch' => $owner->auth_epoch,
            'password' => $owner->password,
        ];
        foreach ([
            [['account_status' => 'suspended'], 403],
            [['role' => 'customer'], 403],
            [['auth_epoch' => (int) $owner->auth_epoch + 1], 409],
            [['password' => 'rotated-synthetic-password-hash'], 409],
        ] as [$change, $status]) {
            DB::table('users')->where('id', $owner->id)->update(array_replace($initial, $change));
            foreach ($remote + $billing as $action => $attempt) {
                try {
                    $attempt();
                    $this->fail('Revoked partner account reached a private remote/finance operation.');
                } catch (HttpException $e) {
                    // Private financial reads use the shared current-reader contract.
                    $this->assertSame(isset($billing[$action]) ? 409 : $status, $e->getStatusCode(), $action);
                }
            }
        }
        DB::table('users')->where('id', $owner->id)->update($initial);

        // Even a still-authenticated former owner cannot operate on a
        // provider model captured before the reassignment committed.
        DB::table('ocpi_providers')->where('id', $provider->id)->update([
            'owner_id' => $other->id,
        ]);
        foreach ($remote + $billing as $action => $attempt) {
            try {
                $attempt();
                $this->fail('A stale provider model exposed its replacement tenant.');
            } catch (HttpException $e) {
                $this->assertSame(isset($billing[$action]) ? 404 : 409, $e->getStatusCode(), $action);
            }
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_stale_admin_mutations_cannot_create_or_authorize_ocpi_providers(): void
    {
        $owner = $this->vendor();
        $provider = $this->pending($owner);
        $admin = $this->admin();
        config(['ocpi.connections.other-unassigned' => [
            'origin' => 'https://partner.example',
            'authorization_token' => base64_encode('other-synthetic-secret'),
            'environment' => 'sandbox',
        ]]);
        $createRequest = Request::create('/api/ocpi/providers', 'POST', [
            'owner_id' => $owner->id,
            'name' => 'Must not materialize',
            'connection_key' => 'other-unassigned',
            'authorized' => true,
        ]);
        $createRequest->setUserResolver(static fn () => $admin);
        $authorizationRequest = Request::create(
            '/api/ocpi/providers/'.$provider->id.'/authorization', 'PATCH', ['authorized' => true]
        );
        $authorizationRequest->setUserResolver(static fn () => $admin);
        $controller = app(OcpiController::class);
        $initial = [
            'role' => 'admin',
            'account_status' => 'active',
            'auth_epoch' => $admin->auth_epoch,
            'password' => $admin->password,
        ];
        foreach ([
            [['account_status' => 'suspended'], 403],
            [['role' => 'customer'], 403],
            [['auth_epoch' => (int) $admin->auth_epoch + 1], 409],
            [['password' => 'other-synthetic-password-hash'], 409],
        ] as [$change, $expectedStatus]) {
            DB::table('users')->where('id', $admin->id)->update(array_replace($initial, $change));
            foreach ([
                static fn () => $controller->create($createRequest, app(PartnerClient::class)),
                static fn () => $controller->authorization($authorizationRequest, $provider, app(PartnerClient::class)),
            ] as $mutation) {
                try {
                    $mutation();
                    $this->fail('A revoked Admin request modified OCPI provider authority.');
                } catch (HttpException $error) {
                    $this->assertSame($expectedStatus, $error->getStatusCode());
                }
            }
        }
        $this->assertDatabaseCount('ocpi_providers', 1);
        $this->assertFalse($provider->fresh()->authorized);
        $this->assertSame(0, $provider->fresh()->authorization_revision);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'ocpi.provider_authorized', 'subject' => (string) $provider->id,
        ]);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'ocpi.provider_created',
        ]);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_stale_route_bound_provider_cannot_authorize_after_owner_transfer(): void
    {
        $original = $this->vendor();
        $replacement = $this->vendor();
        $provider = $this->pending($original);
        $stale = $provider->fresh();
        DB::table('ocpi_providers')->where('id', $provider->id)->update(['owner_id' => $replacement->id]);

        $admin = $this->admin();
        $request = Request::create('/api/ocpi/providers/'.$provider->id.'/authorization',
            'PATCH', ['authorized' => true]);
        $request->setUserResolver(static fn () => $admin);
        try {
            app(OcpiController::class)->authorization($request, $stale, app(PartnerClient::class));
            $this->fail('An old provider model authorized its replacement owner.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $fresh = $provider->fresh();
        $this->assertSame($replacement->id, $fresh->owner_id);
        $this->assertFalse($fresh->authorized);
        $this->assertSame(0, $fresh->authorization_revision);
        $this->assertDatabaseMissing('audit_events', ['action' => 'ocpi.provider_authorized',
            'subject' => (string) $provider->id]);
        Http::assertNothingSent();
    }

    public function test_revocation_remains_possible_for_ineligible_owner_and_reactivation_requires_fresh_eligibility(): void
    {
        $owner = $this->vendor();
        $provider = $this->pending($owner);
        $provider->update([
            'authorized' => true,
            'version' => '2.2.1',
            'endpoints' => ['locations' => 'https://partner.example/locations'],
            'authorization_revision' => 4,
        ]);

        $this->actingAs($this->admin());
        DB::table('users')->where('id', $owner->id)->update(['account_status' => 'suspended']);
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization', ['authorized' => false])
            ->assertOk()->assertJsonPath('authorized', false);
        $this->assertSame(5, $provider->fresh()->authorization_revision);
        $this->assertNull($provider->fresh()->endpoints);
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization', ['authorized' => true])
            ->assertUnprocessable();
        $this->assertSame(5, $provider->fresh()->authorization_revision);

        DB::table('users')->where('id', $owner->id)->update(['account_status' => 'active']);
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization', ['authorized' => true])
            ->assertOk()->assertJsonPath('authorized', true);
        $this->assertSame(6, $provider->fresh()->authorization_revision);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'ocpi.provider_authorized', 'subject' => (string) $provider->id,
        ]);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_eligible_vendor_can_register_without_implicit_roaming_activation(): void
    {
        $owner = $this->vendor();
        $this->actingAs($this->admin());
        $created = $this->postJson('/api/ocpi/providers', [
            'owner_id' => $owner->id,
            'name' => 'Synthetic unapproved operator',
            'connection_key' => 'owner-eligibility',
            'authorized' => false,
        ])->assertCreated()->assertJsonPath('authorized', false)
            ->assertJsonMissingPath('connection_key')->assertJsonMissingPath('endpoints');
        $this->assertDatabaseHas('ocpi_providers', [
            'id' => $created->json('id'),
            'owner_id' => $owner->id,
            'authorized' => false,
        ]);
        Http::assertNothingSent();
    }
}
