<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OcpiContractEvidenceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $provider = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Synthetic review',
            'connection_key' => 'contract-evidence-test', 'authorized' => false]);

        return [$owner, $admin, $provider, '/api/ocpi/providers/'.$provider->id.'/contract-review'];
    }

    private function review(): array
    {
        return ['decision' => 'review', 'reference_id' => 'SYNTHETIC-REF-001',
            'evidence_sha256' => str_repeat('a', 64), 'valid_until' => now('UTC')->addYear()->toDateString(),
            'expected_revision' => 0, 'idempotency_key' => 'synthetic-review-key-001',
            'reason' => 'Synthetic internal document review evidence only.'];
    }

    public function test_review_and_revocation_are_append_only_and_do_not_change_operational_authority(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin)->getJson($path)->assertOk()->assertJsonPath('state', 'missing');
        $this->postJson($path, $this->review())->assertCreated()
            ->assertJsonPath('state', 'internally_reviewed')->assertJsonPath('revision', 1)
            ->assertJsonPath('review_valid_for_current_owner', true)
            ->assertJsonPath('operational_enforcement_available', false)
            ->assertJsonPath('external_partner_acceptance_verified', false)
            ->assertJsonPath('payment_authority', false);
        $this->postJson($path, $this->review())->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->postJson($path, array_replace($this->review(), ['reason' => 'Different synthetic evidence note.']))->assertConflict();
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 1);
        $stored = DB::table('ocpi_partner_contract_reviews')->first();
        $this->assertNotSame($this->review()['reason'], $stored->reason_ciphertext);
        $this->assertSame($this->review()['reason'], Crypt::decryptString($stored->reason_ciphertext));
        $this->getJson($path.'/history')->assertOk()->assertJsonPath('history.data.0.reference_id', 'SYNTHETIC-REF-001');
        $this->actingAs($owner)->getJson($path.'/history')->assertOk()
            ->assertJsonMissingPath('history.data.0.reference_id')
            ->assertJsonMissingPath('history.data.0.reason')->assertJsonMissingPath('history.data.0.evidence_sha256');
        $this->postJson($path, $this->review())->assertForbidden();
        $this->actingAs($admin)->postJson($path, ['decision' => 'revoke', 'expected_revision' => 1,
            'idempotency_key' => 'synthetic-revoke-key-001',
            'reason' => 'Synthetic internal evidence withdrawn.'])->assertCreated()
            ->assertJsonPath('state', 'revoked')->assertJsonPath('revision', 2);
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 2);
        $this->assertFalse($provider->fresh()->authorized);
        Http::assertNothingSent();
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_connection_reassignment_hides_former_review_history_and_rejects_old_key(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin)->postJson($path, $this->review())->assertCreated();
        DB::table('ocpi_providers')->where('id', $provider->id)->update(['connection_key' => 'replacement-contract-fixture']);
        $this->actingAs($owner)->getJson($path)->assertOk()->assertJsonPath('state', 'missing');
        $this->getJson($path.'/history')->assertOk()->assertJsonPath('history.total', 0);
        $this->actingAs($admin)->postJson($path, $this->review())->assertConflict();
        $this->postJson($path, array_replace($this->review(), ['expected_revision' => 1,
            'idempotency_key' => 'synthetic-new-review-001']))->assertCreated();
        $this->getJson(str_replace('/api/ocpi/', '/api/v1/ocpi/', $path).'/history')->assertOk()
            ->assertJsonPath('history.total', 1)->assertJsonPath('history.data.0.revision', 2);
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($path)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_opt_in_administrative_authorization_requires_current_evidence_and_preserves_revocation(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        config(['ocpi.contract_review_authorization_required' => true,
            'ocpi.connections.contract-evidence-test' => ['origin' => 'https://partner.example',
                'authorization_token' => 'synthetic-token'],
            'ocpi.connections.contract-pending-other' => ['origin' => 'https://partner.example',
                'authorization_token' => 'synthetic-token']]);
        $authorization = '/api/ocpi/providers/'.$provider->id.'/authorization';
        $this->actingAs($admin)->postJson('/api/ocpi/providers', ['owner_id' => $owner->id,
            'name' => 'Cannot bypass pending review', 'connection_key' => 'contract-pending-other',
            'authorized' => true])->assertConflict();
        $this->patchJson($authorization, ['authorized' => true])->assertConflict();
        $this->assertFalse($provider->fresh()->authorized);
        $this->postJson($path, $this->review())->assertCreated()
            ->assertJsonPath('administrative_authorization_review_required', true)
            ->assertJsonPath('operational_enforcement_available', false);
        $this->patchJson($authorization, ['authorized' => true])->assertOk()->assertJsonPath('authorized', true);
        $revoke = ['decision' => 'revoke', 'expected_revision' => 1,
            'idempotency_key' => 'synthetic-revoke-gate-001', 'reason' => 'Withdraw synthetic review after deauthorization.'];
        $this->postJson($path, $revoke)->assertConflict();
        $this->patchJson($authorization, ['authorized' => false])->assertOk();
        $this->postJson($path, $revoke)->assertCreated();
        $this->patchJson($authorization, ['authorized' => true])->assertConflict();
        $this->assertFalse($provider->fresh()->authorized);
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 2);
        Http::assertNothingSent();
    }

    public function test_expired_or_corrupt_evidence_denies_new_authorization(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        config(['ocpi.contract_review_authorization_required' => true,
            'ocpi.connections.contract-evidence-test' => ['origin' => 'https://partner.example',
                'authorization_token' => 'synthetic-token']]);
        $this->actingAs($admin)->postJson($path, $this->review())->assertCreated();
        $authorization = '/api/v1/ocpi/providers/'.$provider->id.'/authorization';
        $row = DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id);
        $row->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
        $this->patchJson($authorization, ['authorized' => true])->assertConflict();
        $row->update(['valid_until' => $this->review()['valid_until'], 'evidence_sha256' => str_repeat('x', 64)]);
        $this->getJson($path)->assertOk()->assertJsonPath('review_valid_for_current_owner', false);
        $this->patchJson($authorization, ['authorized' => true])->assertConflict();
        $row->update(['evidence_sha256' => $this->review()['evidence_sha256']]);
        $this->patchJson($authorization, ['authorized' => true])->assertOk();
        Http::assertNothingSent();
    }

    public function test_expired_review_and_suspended_owner_cannot_be_reported_as_current(): void
    {
        [$owner, $admin, $provider, $path] = $this->fixture();
        $this->actingAs($admin)->postJson($path, $this->review())->assertCreated();
        DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
        $this->getJson($path)->assertOk()->assertJsonPath('review_valid_for_current_owner', false);
        DB::table('users')->where('id', $owner->id)->update(['account_status' => 'suspended']);
        $this->postJson($path, array_replace($this->review(), ['expected_revision' => 1,
            'idempotency_key' => 'synthetic-second-review-001']))->assertConflict();
        $this->assertDatabaseCount('ocpi_partner_contract_reviews', 1);
        Http::assertNothingSent();
    }
}
