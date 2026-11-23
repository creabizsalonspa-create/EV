<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Internal review is a licensing/publication software fence, not proof of
 * physical availability or a contract signed by a real OCPI counterparty.
 */
final class OcpiContractReviewDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $customer = User::factory()->create(['role' => 'customer', 'account_status' => 'active']);
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'name' => 'Synthetic licensed source',
            'connection_key' => 'contract-discovery-test', 'authorized' => true,
            'version' => '2.2.1',
        ]);
        $record = OcpiRecord::create([
            'ocpi_provider_id' => $provider->id, 'module' => 'locations',
            'country_code' => 'AE', 'party_id' => 'TST', 'external_id' => 'station-contract-001',
            'content_hash' => str_repeat('a', 64),
            'payload' => ['private' => 'DO-NOT-RELEASE-OCPI-CONTRACT-BODY'],
            'normalized' => [
                'name' => 'Synthetic licensed charge point', 'publish' => true,
                'address' => 'Source only', 'city' => 'Dubai', 'country' => 'ARE',
                'connector_standards' => ['IEC_62196_T2_COMBO'],
                'evses' => [], 'latitude' => 25.1, 'longitude' => 55.1,
            ],
            'source_updated_at' => now('UTC')->subMinutes(10),
            'received_at' => now('UTC'),
        ]);

        return [$customer, $owner, $admin, $provider, $record];
    }

    private function approve(User $admin, OcpiProvider $provider, int $revision = 0): void
    {
        $this->actingAs($admin)->postJson(
            '/api/ocpi/providers/'.$provider->id.'/contract-review',
            [
                'decision' => 'review',
                'reference_id' => 'REVIEW-SYNTHETIC-001',
                'evidence_sha256' => str_repeat('b', 64),
                'valid_until' => now('UTC')->addYear()->toDateString(),
                'expected_revision' => $revision,
                'idempotency_key' => 'review-contract-source-'.str_pad((string) $revision, 5, '0', STR_PAD_LEFT),
                'reason' => 'Synthetic internal reviewer recorded only a manual document digest.',
            ]
        )->assertCreated()->assertJsonPath('external_partner_acceptance_verified', false);
    }

    private function expectPartnerCount(int $count): void
    {
        foreach ([
            '/api/roaming/locations',
            '/api/roaming/locations?provider=Synthetic',
            '/api/discovery/search?scope=partner',
            '/api/v1/discovery/search?scope=partner',
            '/api/discovery/search?scope=partner&operator=Synthetic',
            '/api/discovery/search?scope=partner&q=licensed',
        ] as $path) {
            $this->getJson($path)->assertOk()->assertJsonPath('total', $count)
                ->assertJsonCount($count, 'data')
                ->assertDontSee('DO-NOT-RELEASE-OCPI-CONTRACT-BODY');
        }
    }

    public function test_opt_in_licensing_visibility_restricts_search_favorites_and_owner_transfer(): void
    {
        [$customer, $owner, $admin, $provider, $record] = $this->fixture();
        $this->actingAs($customer);
        // Legacy software remains unchanged until the operator explicitly
        // enables the policy and has installed its audit schema.
        $this->expectPartnerCount(1);
        config(['ocpi.contract_review_required' => true]);
        $this->expectPartnerCount(0);
        $this->putJson('/api/roaming/favorites/'.$record->id)->assertNotFound();
        $this->assertDatabaseCount('partner_location_favorites', 0);

        $this->approve($admin, $provider);
        $this->actingAs($customer);
        $this->expectPartnerCount(1);
        $this->putJson('/api/roaming/favorites/'.$record->id)->assertOk()
            ->assertJsonPath('is_favorite', true);
        $this->getJson('/api/roaming/favorites')->assertOk()
            ->assertJsonPath('data.0.location_available', true)
            ->assertJsonPath('data.0.evidence', 'PARTNER_REPORTED_UNVERIFIED')
            ->assertJsonPath('data.0.remote_control', false);
        $this->assertDatabaseCount('partner_location_favorites', 1);

        // Natural expiry cannot keep exposing source data on the public map.
        DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
        $this->expectPartnerCount(0);
        $this->getJson('/api/roaming/favorites')->assertOk()
            ->assertJsonPath('data.0.location_available', false)
            ->assertJsonPath('data.0.location', null)
            ->assertJsonPath('data.0.catalog_age_policy', null)
            ->assertJsonPath('data.0.provider_label', null);
        $this->putJson('/api/roaming/favorites/'.$record->id)->assertNotFound();
        // Deleting a saved tombstone never requires a renewed license.
        $this->deleteJson('/api/roaming/favorites/'.$record->id)->assertOk()
            ->assertJsonPath('is_favorite', false);

        $newOwner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $provider->update(['owner_id' => $newOwner->id]);
        $this->expectPartnerCount(0);
        $this->approve($admin, $provider, 1);
        $this->actingAs($customer);
        $this->expectPartnerCount(1);

        $newOwner->forceFill(['role' => 'customer'])->save();
        $this->expectPartnerCount(0);
        Http::assertNothingSent();
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_later_revocation_cannot_be_hidden_by_an_earlier_approved_review(): void
    {
        [$customer, $owner, $admin, $provider, $record] = $this->fixture();
        $this->approve($admin, $provider);
        $this->actingAs($customer);
        config(['ocpi.contract_review_required' => true]);
        $this->expectPartnerCount(1);

        // A policy-off Admin may revoke the review while a provider remains
        // authorized. When enforcement is re-enabled, the most recent audit
        // decision must always win over prior approved review rows.
        config(['ocpi.contract_review_required' => false]);
        $this->actingAs($admin)->postJson(
            '/api/ocpi/providers/'.$provider->id.'/contract-review',
            [
                'decision' => 'revoke', 'expected_revision' => 1,
                'idempotency_key' => 'revoke-contract-source-00002',
                'reason' => 'Withdraw the synthetic internal review and block further public discovery.',
            ]
        )->assertCreated()->assertJsonPath('state', 'revoked');
        config(['ocpi.contract_review_required' => true]);
        $this->actingAs($customer);
        $this->expectPartnerCount(0);
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 2);
        $this->assertDatabaseCount('charging_sessions', 0);
        Http::assertNothingSent();
    }
}
