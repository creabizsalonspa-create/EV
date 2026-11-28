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
use Tests\TestCase;

/** Public OCPI discovery is separate from durable owner/private source evidence. */
class OcpiPublicOwnerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $customer = User::factory()->create(['role' => 'customer']);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'name' => 'Suspension test operator',
            'connection_key' => 'visibility-fixture', 'authorized' => true, 'version' => '2.2.1',
        ]);
        $location = OcpiRecord::create([
            'ocpi_provider_id' => $provider->id, 'module' => 'locations',
            'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'visibility-test',
            'payload' => ['fixture_only' => true],
            'content_hash' => str_repeat('a', 64),
            'normalized' => [
                'country_code' => 'AE', 'party_id' => 'TST', 'id' => 'visibility-test',
                'name' => 'Visibility fixture', 'address' => 'TEST public road',
                'city' => 'Dubai', 'country' => 'ARE', 'publish' => true,
                'latitude' => '25.2', 'longitude' => '55.3',
                'connector_standards' => ['IEC_62196_T2_COMBO'], 'evses' => [],
            ],
            'source_updated_at' => now()->subMinute(), 'received_at' => now(),
        ]);

        return compact('owner', 'customer', 'provider', 'location');
    }

    private function publicPages(int $count): void
    {
        $this->getJson('/api/roaming/locations')->assertOk()->assertJsonPath('total', $count);
        $this->getJson('/api/roaming/locations?provider=Suspension')->assertOk()->assertJsonPath('total', $count);
        foreach (['/api/discovery/search', '/api/v1/discovery/search'] as $route) {
            $this->getJson($route.'?scope=partner')->assertOk()->assertJsonPath('total', $count);
            $this->getJson($route.'?scope=partner&operator=Suspension')->assertOk()->assertJsonPath('total', $count);
        }
    }

    public function test_suspending_the_provider_owner_hides_both_discovery_catalogs_and_redacts_private_favorites(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer']);
        $favoriteUrl = '/api/roaming/favorites/'.$f['location']->id;
        $this->publicPages(1);
        $this->putJson($favoriteUrl)->assertOk();
        $this->getJson('/api/roaming/favorites')->assertJsonPath('data.0.location_available', true);

        $f['owner']->forceFill(['account_status' => 'suspended'])->save();
        $this->publicPages(0);
        $this->getJson('/api/roaming/favorites')->assertOk()
            ->assertJsonPath('data.0.location_available', false)
            ->assertJsonPath('data.0.location', null)
            ->assertJsonPath('data.0.provider_label', null)
            ->assertJsonPath('data.0.source_updated_at', null)
            ->assertDontSee('TEST public road');
        $this->putJson($favoriteUrl)->assertNotFound();
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('partner_location_favorites', 1);

        $this->deleteJson($favoriteUrl)->assertOk();
        $this->assertDatabaseCount('partner_location_favorites', 0);
        Http::assertNothingSent();
    }

    public function test_contract_review_hides_unapproved_catalog_before_pagination_and_redacts_favorites(): void
    {
        $f = $this->fixture();
        $favoriteUrl = '/api/roaming/favorites/'.$f['location']->id;
        $reviewUrl = '/api/ocpi/providers/'.$f['provider']->id.'/contract-review';
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($f['customer'])->putJson($favoriteUrl)->assertOk();
        config(['ocpi.contract_review_authorization_required' => true]);
        $this->publicPages(0);
        $this->getJson('/api/roaming/favorites')->assertOk()
            ->assertJsonPath('data.0.location_available', false)
            ->assertJsonPath('data.0.location', null)->assertDontSee('TEST public road');
        $this->putJson($favoriteUrl)->assertNotFound();
        $review = ['decision' => 'review', 'reference_id' => 'REVIEW-FIXTURE-01',
            'evidence_sha256' => str_repeat('a', 64), 'valid_until' => now('UTC')->addYear()->toDateString(),
            'expected_revision' => 0, 'idempotency_key' => 'visibility-review-001',
            'reason' => 'Synthetic evidence for public catalog review only.'];
        $this->actingAs($admin)->postJson($reviewUrl, $review)->assertCreated();
        $this->actingAs($f['customer']);
        $this->publicPages(1);
        $this->getJson('/api/roaming/favorites')->assertJsonPath('data.0.location_available', true);
        $this->putJson($favoriteUrl)->assertOk();

        $rows = DB::table('ocpi_partner_contract_reviews')->where('provider_id', $f['provider']->id);
        foreach ([
            ['valid_until' => now('UTC')->subDay()->toDateString()],
            ['evidence_sha256' => str_repeat('z', 64)],
            ['reference_id' => 'bad reference'],
            ['connection_key' => 'former-connection'],
        ] as $corruption) {
            $rows->update($corruption);
            $this->publicPages(0);
            $this->getJson('/api/roaming/favorites')->assertJsonPath('data.0.location', null);
            $this->putJson($favoriteUrl)->assertNotFound();
            $rows->update(['valid_until' => $review['valid_until'], 'reference_id' => $review['reference_id'],
                'evidence_sha256' => $review['evidence_sha256'], 'connection_key' => 'visibility-fixture']);
        }

        // A later invalid review must never fall back to the earlier approved row.
        $this->actingAs($admin)->postJson($reviewUrl, array_replace($review, [
            'expected_revision' => 1, 'idempotency_key' => 'visibility-review-002',
        ]))->assertCreated();
        $rows->where('revision', 2)->update(['decision' => 'revoke']);
        $this->actingAs($f['customer']);
        $this->publicPages(0);
        $this->deleteJson($favoriteUrl)->assertOk();
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('partner_location_favorites', 0);
        // Opt-in gate is reversible, and never alters historical source records.
        config(['ocpi.contract_review_authorization_required' => false]);
        $this->publicPages(1);
        Http::assertNothingSent();
    }

    public function test_direct_catalog_ingestion_cannot_bypass_contract_review(): void
    {
        $f = $this->fixture();
        config(['ocpi.contract_review_authorization_required' => true]);
        $service = app(RecordSynchronizer::class);
        foreach (['locations', 'tariffs'] as $module) {
            try {
                $service->ingest($f['provider'], $module, []);
                $this->fail('An unreviewed catalog must not be ingested.');
            } catch (OcpiException) {
                $this->assertNull($f['provider']->fresh()->last_synced_at);
            }
        }
        $this->assertDatabaseCount('ocpi_records', 1);
        Http::assertNothingSent();
    }

    public function test_suspended_owner_cannot_gain_new_provider_authorization_or_request_partner_sync(): void
    {
        $f = $this->fixture();
        $f['owner']->forceFill(['account_status' => 'suspended'])->save();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $providerPath = '/api/ocpi/providers/'.$f['provider']->id;
        $this->postJson($providerPath.'/negotiate')->assertConflict();
        $this->postJson($providerPath.'/sync/locations')->assertConflict();

        // Revocation must remain possible, but reauthorization must require
        // an active vendor owner, even for an authenticated administrator.
        $this->patchJson($providerPath.'/authorization', ['authorized' => false])->assertOk();
        $this->patchJson($providerPath.'/authorization', ['authorized' => true])->assertConflict();

        config(['ocpi.connections.suspended-fixture' => [
            'origin' => 'https://peer.example',
            'authorization_token' => base64_encode('synthetic-only'),
        ]]);
        $this->postJson('/api/ocpi/providers', [
            'owner_id' => $f['owner']->id, 'name' => 'Suspended test peer',
            'connection_key' => 'suspended-fixture', 'authorized' => true,
        ])->assertConflict();

        $this->getJson('/api/roaming/locations')->assertJsonPath('total', 0);
        $this->assertDatabaseCount('ocpi_providers', 1);
        $this->assertDatabaseCount('ocpi_records', 1);
        Http::assertNothingSent();
    }

    public function test_admin_can_reconcile_legacy_session_history_without_republishing_suspended_owner_locations(): void
    {
        $f = $this->fixture();
        config(['ocpi.connections.visibility-fixture' => [
            'origin' => 'https://peer.example',
            'authorization_token' => base64_encode('offline-only-history'),
        ]]);
        $f['provider']->update([
            'endpoints' => ['sessions' => 'https://peer.example/sessions'],
        ]);
        Http::fake(['https://peer.example/sessions' => Http::response([
            'status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => [],
        ])]);
        $f['owner']->forceFill(['account_status' => 'suspended'])->save();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $path = '/api/ocpi/providers/'.$f['provider']->id.'/sync/';
        $this->postJson($path.'locations')->assertConflict();
        $this->postJson($path.'sessions')->assertOk()->assertJsonPath('counts.created', 0)
            ->assertJsonPath('billing_eligible', false)
            ->assertJsonPath('remote_control', false);
        $this->getJson('/api/roaming/locations')->assertOk()->assertJsonPath('total', 0);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_operator_suspended_during_remote_negotiation_cannot_commit_capabilities(): void
    {
        $f = $this->fixture();
        config(['ocpi.connections.visibility-fixture' => [
            'origin' => 'https://peer.example',
            'authorization_token' => base64_encode('offline-only-negotiation'),
        ]]);
        $f['provider']->update(['version' => null, 'endpoints' => null]);
        Http::fake(function ($request) use ($f) {
            if (str_ends_with($request->url(), '/ocpi/versions')) {
                return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(),
                    'data' => [['version' => '2.2.1', 'url' => 'https://peer.example/ocpi/2.2.1']]]);
            }
            $f['owner']->forceFill(['account_status' => 'suspended'])->save();

            return Http::response(['status_code' => 1000, 'timestamp' => now()->toISOString(),
                'data' => ['version' => '2.2.1', 'endpoints' => [
                    ['identifier' => 'locations', 'role' => 'SENDER', 'url' => 'https://peer.example/locations'],
                ]]]);
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/ocpi/providers/'.$f['provider']->id.'/negotiate')->assertConflict();
        $this->assertNull($f['provider']->fresh()->version);
        $this->assertNull($f['provider']->fresh()->endpoints);
        $this->getJson('/api/roaming/locations')->assertJsonPath('total', 0);
        $this->assertDatabaseCount('ocpi_records', 1);
        Http::assertSentCount(2);
    }

    public function test_catalog_pagination_stops_partner_egress_when_owner_suspended_between_pages(): void
    {
        $f = $this->fixture();
        config(['ocpi.connections.visibility-fixture' => [
            'origin' => 'https://peer.example',
            'authorization_token' => base64_encode('offline-only-catalog'),
        ]]);
        $f['provider']->update(['endpoints' => ['locations' => 'https://peer.example/locations']]);
        Http::fake(function () use ($f) {
            $f['owner']->forceFill(['account_status' => 'suspended'])->save();

            return Http::response(
                ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => []],
                200, ['Link' => '<https://peer.example/locations?page=2>; rel="next"']
            );
        });
        try {
            app(PartnerClient::class)->pull($f['provider']->fresh(), 'locations');
            $this->fail('A second catalog page was requested after owner suspension.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('Inactive operator owner', $error->getMessage());
        }
        Http::assertSentCount(1);
        try {
            app(PartnerClient::class)->pull($f['provider']->fresh(), 'locations');
            $this->fail('A suspended provider sent another catalog request.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('Inactive operator owner', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->getJson('/api/roaming/locations')->assertJsonPath('total', 0);
    }

    public function test_late_partner_catalog_batch_cannot_mutate_records_after_owner_suspension(): void
    {
        $f = $this->fixture();
        $incoming = [
            'country_code' => 'AE', 'party_id' => 'TST', 'id' => 'late-catalog',
            'publish' => true, 'name' => 'Late reported catalog',
            'address' => 'Synthetic Road', 'city' => 'Dubai', 'country' => 'ARE',
            'coordinates' => ['latitude' => '25.2', 'longitude' => '55.3'],
            'evses' => [['uid' => 'one', 'status' => 'UNKNOWN', 'connectors' => [
                ['id' => '1', 'standard' => 'IEC_62196_T2_COMBO'],
            ]]], 'last_updated' => now()->subMinute()->toISOString(),
        ];
        $f['owner']->forceFill(['account_status' => 'suspended'])->save();
        try {
            app(RecordSynchronizer::class)->ingest($f['provider'], 'locations', [$incoming]);
            $this->fail('A late source batch was ingested after provider-owner suspension.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('Inactive operator owner', $error->getMessage());
        }
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseMissing('ocpi_records', ['external_id' => 'late-catalog']);
        Http::assertNothingSent();
    }

    public function test_changed_owner_role_is_not_public_approval_and_reactivation_restores_read_only_catalog(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['customer']);
        $this->publicPages(1);
        $f['owner']->forceFill(['role' => 'admin'])->save();
        $this->publicPages(0);
        $this->putJson('/api/roaming/favorites/'.$f['location']->id)->assertNotFound();

        $f['owner']->forceFill(['role' => 'vendor', 'account_status' => 'active'])->save();
        $this->publicPages(1);
        $this->putJson('/api/roaming/favorites/'.$f['location']->id)->assertOk();
        $this->assertDatabaseCount('ocpi_records', 1);
        $this->assertDatabaseCount('partner_location_favorites', 1);
        Http::assertNothingSent();
    }
}
