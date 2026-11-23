<?php

namespace Tests\Feature;

use App\Http\Controllers\OcpiContractReviewController;
use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class OcpiPartnerContractReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ocpi.connections.contract-test' => [
                'origin' => 'https://partner.example',
                'authorization_token' => base64_encode('synthetic-private-bootstrap'),
                'environment' => 'sandbox',
            ],
            'ocpi.connections.contract-test-unassigned' => [
                'origin' => 'https://partner.example',
                'authorization_token' => base64_encode('synthetic-private-bootstrap'),
                'environment' => 'sandbox',
            ],
        ]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'name' => 'Synthetic pending partner',
            'connection_key' => 'contract-test', 'authorized' => false,
        ]);

        return [$owner, $admin, $provider, '/api/ocpi/providers/'.$provider->id.'/contract-review'];
    }

    private function review(int $revision = 0, string $key = 'review-contract-00001'): array
    {
        return [
            'decision' => 'review',
            'reference_id' => 'LEGAL-SYNTHETIC-CASE-001',
            'evidence_sha256' => str_repeat('a', 64),
            'valid_until' => now('UTC')->addYear()->toDateString(),
            'expected_revision' => $revision,
            'idempotency_key' => $key,
            'reason' => 'Synthetic record independently checked by an internal administrator.',
        ];
    }

    public function test_opt_in_contract_review_requires_pending_provider_and_allows_only_recorded_approval(): void
    {
        config(['ocpi.contract_review_required' => true]);
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin)->postJson('/api/ocpi/providers', [
            'owner_id' => $owner->id, 'name' => 'Cannot skip internal review',
            'connection_key' => 'contract-test-unassigned', 'authorized' => true,
        ])->assertConflict(); // Review is mandatory for a new active partner.
        $this->assertDatabaseCount('ocpi_providers', 1);
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization',
            ['authorized' => true])->assertConflict();
        $this->getJson($path)->assertOk()
            ->assertJsonPath('state', 'missing')
            ->assertJsonPath('revision', 0)
            ->assertJsonPath('review_valid_for_current_owner', false)
            ->assertJsonPath('external_partner_acceptance_verified', false)
            ->assertJsonPath('contract_legally_executed_verified', false);

        $receipt = $this->postJson($path, $this->review())->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('state', 'internally_reviewed')
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('review_valid_for_current_owner', true)
            ->assertJsonPath('external_partner_acceptance_verified', false)
            ->assertJsonPath('live_roaming_enabled', false)
            ->assertJsonPath('payment_authority', false)
            ->assertJsonPath('idempotent_replay', false);
        $this->assertArrayNotHasKey('reason', $receipt->json());
        $this->assertArrayNotHasKey('evidence_sha256', $receipt->json());
        $this->postJson($path, $this->review())->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'ocpi.contract_review')->count());
        $this->getJson($path.'/history')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('scope', 'private_ocpi_contract_review_history')
            ->assertJsonPath('current_revision', 1)
            ->assertJsonPath('history.total', 1)
            ->assertJsonPath('history.data.0.reason', $this->review()['reason'])
            ->assertJsonPath('history.data.0.reference_id', 'LEGAL-SYNTHETIC-CASE-001')
            ->assertJsonPath('history.data.0.evidence_sha256', str_repeat('a', 64))
            ->assertJsonPath('payment_authority', false);
        $this->getJson($path.'/history?page=0')->assertUnprocessable();

        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization',
            ['authorized' => true])->assertOk()->assertJsonPath('authorized', true);
        $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonPath('review_valid_for_current_owner', true)
            ->assertJsonPath('contract_legally_executed_verified', false);
        $this->getJson($path.'/history')->assertOk()
            ->assertJsonPath('history.total', 1)
            ->assertJsonMissingPath('history.data.0.reason')
            ->assertJsonMissingPath('history.data.0.reference_id')
            ->assertJsonMissingPath('history.data.0.evidence_sha256');
        $this->postJson($path, $this->review(1, 'vendor-cannot-review-001'))->assertForbidden();
        $this->actingAs($admin);
        $revoke = [
            'decision' => 'revoke', 'expected_revision' => 1,
            'idempotency_key' => 'revoke-contract-0001',
            'reason' => 'Synthetic internal contract review must be withdrawn for reassessment.',
        ];
        $this->postJson($path, $revoke)->assertConflict();
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization',
            ['authorized' => false])->assertOk();
        $this->postJson($path, $revoke)->assertCreated()
            ->assertJsonPath('state', 'revoked')
            ->assertJsonPath('review_valid_for_current_owner', false)
            ->assertJsonPath('revision', 2);
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization',
            ['authorized' => true])->assertConflict();
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 2);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_contract_review_is_tenant_scoped_and_stale_admin_or_owner_transfer_fail_closed(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin)->postJson($path, $this->review())->assertCreated();
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->getJson($path)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))
            ->getJson($path)->assertNotFound();

        $stored = DB::table('ocpi_partner_contract_reviews')->first();
        $this->assertNotSame($this->review()['reason'], $stored->reason_ciphertext);
        $this->assertSame($this->review()['reason'], Crypt::decryptString($stored->reason_ciphertext));
        $this->assertSame(str_repeat('a', 64), $stored->evidence_sha256);

        $snapshot = Request::create($path, 'GET');
        $snapshot->setUserResolver(static fn () => $admin);
        $controller = app(OcpiContractReviewController::class);
        $initial = ['role' => 'admin', 'account_status' => 'active',
            'auth_epoch' => $admin->auth_epoch, 'password' => $admin->password];
        foreach ([
            [['account_status' => 'suspended'], 403],
            [['role' => 'customer'], 403],
            [['auth_epoch' => (int) $admin->auth_epoch + 1], 409],
            [['password' => 'synthetic-password-rotation'], 409],
        ] as [$change, $status]) {
            DB::table('users')->where('id', $admin->id)->update(array_replace($initial, $change));
            try {
                $controller->show($snapshot, $provider);
                $this->fail('A stale Admin request read private contract evidence.');
            } catch (HttpException $error) {
                $this->assertSame($status, $error->getStatusCode());
            }
        }
        DB::table('users')->where('id', $admin->id)->update($initial);

        $replacement = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        DB::table('ocpi_providers')->where('id', $provider->id)
            ->update(['owner_id' => $replacement->id]);
        try {
            $controller->show($snapshot, $provider);
            $this->fail('A captured provider should not reveal a former owners contract.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        // The original owner's operation key cannot be replayed as a
        // successful approval for a different vendor after reassignment.
        $this->actingAs($admin)->postJson($path, $this->review())
            ->assertConflict();
        $this->actingAs($replacement)->getJson($path)->assertOk()
            ->assertJsonPath('state', 'missing')
            ->assertJsonPath('review_valid_for_current_owner', false)
            ->assertJsonPath('revision', 1);
        $this->getJson($path.'/history')->assertOk()->assertJsonPath('history.total', 0)
            ->assertJsonPath('history.data', []);
        $this->actingAs($owner)->getJson($path)->assertNotFound();
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 1);
        Http::assertNothingSent();
    }

    public function test_optional_contract_policy_blocks_network_dispatch_when_review_expires_or_owner_transfers(): void
    {
        config(['ocpi.contract_review_required' => true]);
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin)->postJson($path, $this->review())->assertCreated();
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization',
            ['authorized' => true])->assertOk();

        Http::fake([
            'https://partner.example/ocpi/versions' => Http::response([
                'status_code' => 1000,
                'timestamp' => '2026-11-23T08:00:00Z',
                'data' => [[
                    'version' => '2.2.1',
                    'url' => 'https://partner.example/ocpi/2.2.1',
                ]],
            ]),
            'https://partner.example/ocpi/2.2.1' => Http::response([
                'status_code' => 1000,
                'timestamp' => '2026-11-23T08:00:00Z',
                'data' => [
                    'version' => '2.2.1',
                    'endpoints' => [[
                        'identifier' => 'locations',
                        'role' => 'SENDER',
                        'url' => 'https://partner.example/locations',
                    ]],
                ],
            ]),
        ]);
        $client = app(PartnerClient::class);
        $this->assertArrayHasKey('locations', $client->negotiate($provider->fresh()));
        Http::assertSentCount(2);

        // Natural expiry is not a remote permission merely because the
        // provider's authorized flag remains set in an earlier generation.
        DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
        try {
            $client->negotiate($provider->fresh());
            $this->fail('Expired internal contract evidence dispatched a remote OCPI request.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('internal contract review', $error->getMessage());
        }
        Http::assertSentCount(2);
        try {
            app(RecordSynchronizer::class)->ingest($provider->fresh(), 'locations', []);
            $this->fail('Expired contract review committed a private partner source synchronization.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('contract review expired', $error->getMessage());
        }
        $this->assertDatabaseCount('ocpi_records', 0);

        // An existing digest cannot authorize a newly assigned owner, even
        // if an earlier integration erroneously left authorized=true.
        $replacement = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        DB::table('ocpi_providers')->where('id', $provider->id)
            ->update(['owner_id' => $replacement->id]);
        try {
            $client->negotiate($provider->fresh());
            $this->fail('A prior owner contract review dispatched under new ownership.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('internal contract review', $error->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 1);
        $this->assertDatabaseCount('charging_sessions', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_expired_contract_denies_managed_credential_registration_before_peer_discovery(): void
    {
        config(['ocpi.contract_review_required' => true]);
        [$owner, $admin, $provider] = $this->fixture();
        // Existing authorized providers can predate the manual-review policy;
        // they must not use that grandfathered flag to dispatch new exchange.
        $provider->forceFill(['authorized' => true])->save();
        $request = [
            'action' => 'register',
            'reason' => 'Synthetic approval attempt after an expired internal contract.',
            'idempotency_key' => 'contract-exchange-00001',
        ];
        try {
            app(CredentialExchange::class)->exchange(
                $provider, $admin->id, $request, $admin
            );
            $this->fail('Stale contractual evidence permitted credential discovery.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
        $this->assertDatabaseCount('ocpi_credential_links', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_contract_review_rejects_stale_revision_reused_key_expired_date_and_owner_suspension(): void
    {
        config(['ocpi.contract_review_required' => true]);
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin);
        $this->postJson($path, $this->review())->assertCreated();
        $this->postJson($path, $this->review(0, 'contract-second-0001'))->assertConflict();
        $incorrect = $this->review();
        $incorrect['reason'] = 'Different synthetic claim against the same idempotency key.';
        $this->postJson($path, $incorrect)->assertConflict();
        $past = $this->review(1, 'contract-past-00001');
        $past['valid_until'] = now('UTC')->subDay()->toDateString();
        $this->postJson($path, $past)->assertUnprocessable();
        $owner->forceFill(['account_status' => 'suspended'])->save();
        $this->postJson($path, $this->review(1, 'suspended-owner-001'))->assertConflict();
        $this->patchJson('/api/ocpi/providers/'.$provider->id.'/authorization',
            ['authorized' => true])->assertUnprocessable();
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 1);
        $this->assertDatabaseCount('ocpi_providers', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        Http::assertNothingSent();
    }
}
