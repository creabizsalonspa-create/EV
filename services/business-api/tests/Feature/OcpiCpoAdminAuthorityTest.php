<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\ContractCounterpartyIdentity;
use App\Services\Ocpi\CpoCatalogPublisher;
use App\Services\Ocpi\CredentialExchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** CPO publication and peer delivery must never accept a stale Admin snapshot. */
final class OcpiCpoAdminAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cpo_source_read_checks_current_admin_role_status_epoch_and_password(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id,
            'name' => 'Synthetic private CPO candidate',
            'connection_key' => 'cpo-admin-revocation-fixture',
            'authorized' => false,
        ]);
        $publisher = app(CpoCatalogPublisher::class);
        // There is no approved CPO contract and no allowed outbound API.
        $this->assertSame(['configured' => false], $publisher->show($admin, $provider));

        $initial = [
            'role' => 'admin',
            'account_status' => 'active',
            'auth_epoch' => $admin->auth_epoch,
            'password' => $admin->password,
        ];
        $adminCalls = [
            static fn () => $publisher->show($admin, $provider),
            static fn () => $publisher->change($admin, $provider, 'tariffs', 1, 'publish', null),
            static fn () => $publisher->push($admin, $provider, 'tariffs', 1,
                '2026-11-23T00:00:00Z', 'unauthorized-source-key'),
            static fn () => $publisher->reconcile($admin, $provider, 'tariffs', 1,
                '2026-11-23T00:00:00Z'),
        ];
        foreach ([
            ['account_status' => 'suspended'],
            ['role' => 'customer'],
            ['auth_epoch' => (int) $admin->auth_epoch + 1],
            ['password' => 'different-synthetic-password-hash'],
        ] as $change) {
            DB::table('users')->where('id', $admin->id)->update(array_replace($initial, $change));
            foreach ($adminCalls as $call) {
                try {
                    $call();
                    $this->fail('A stale Admin request reached private CPO publication or delivery.');
                } catch (HttpException $error) {
                    $this->assertSame(403, $error->getStatusCode());
                }
            }
        }
        DB::table('users')->where('id', $admin->id)->update($initial);
        $this->assertSame(['configured' => false], $publisher->show($admin, $provider));

        $forgedRole = $admin->replicate();
        $forgedRole->id = $admin->id;
        $forgedRole->role = 'customer';
        try {
            $publisher->show($forgedRole, $provider);
            $this->fail('A non-Admin session must not become an Admin through an ID-only lookup.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $newOwner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        DB::table('ocpi_providers')->where('id', $provider->id)
            ->update(['owner_id' => $newOwner->id]);
        $staleOwnerReadsAndMutations = [
            ...$adminCalls,
            static fn () => $publisher->read($provider,
                (object) ['generation' => 1, 'local_token_digest' => str_repeat('a', 64)],
                'locations', ['offset' => 0, 'limit' => 1], null),
        ];
        foreach ($staleOwnerReadsAndMutations as $call) {
            try {
                $call();
                $this->fail('A stale CPO provider read or published under a replacement owner.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 0);
        $this->assertDatabaseCount('ocpi_cpo_catalog', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_expired_owner_review_fences_cpo_publication_even_when_credential_binding_is_active(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        config([
            'ocpi.local_credential_profiles.catalog' => [
                'url' => 'https://platform.example/api/roaming-protocol/versions',
                'roles' => [['role' => 'CPO', 'country_code' => 'AE', 'party_id' => 'EVX',
                    'business_details' => ['name' => 'Synthetic CPO']]],
            ],
            'ocpi.connections.cpo-review-fence' => [
                'managed_credentials' => true,
                'origin' => 'https://emsp.example',
                'authorization_token' => base64_encode('SYNTHETIC-BOOTSTRAP'),
                'party_country' => 'NL',
                'party_id' => 'ABC',
                'party_role' => 'EMSP',
                'environment' => 'sandbox',
                'local_credential_profile' => 'catalog',
                'cpo_catalog' => ['id' => 'review-fence-fixture', 'sites' => [], 'tariffs' => []],
            ],
        ]);
        $provider = OcpiProvider::create([
            'owner_id' => $owner->id, 'name' => 'Synthetic CPO without internal review',
            'connection_key' => 'cpo-review-fence', 'authorized' => true,
            'version' => '2.2.1',
        ]);
        $token = 'SYNTHETIC-CPO-LOCAL-TOKEN';
        DB::table('ocpi_credential_links')->insert([
            'provider_id' => $provider->id, 'owner_id' => $owner->id,
            'generation' => 1, 'state' => 'active',
            'configuration_hash' => app(CredentialExchange::class)->configurationHash($provider),
            'bootstrap_hash' => hash('sha256', 'SYNTHETIC-BOOTSTRAP'),
            'local_token_digest' => hash('sha256', $token),
            'local_token_ciphertext' => Crypt::encryptString($token),
            'remote_token_ciphertext' => Crypt::encryptString('SYNTHETIC-CPO-PEER-TOKEN'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $publisher = app(CpoCatalogPublisher::class);
        // Default-off policy preserves the merged source's valid CPO flow.
        $this->assertTrue($publisher->show($admin, $provider)['configured']);
        config(['ocpi.contract_review_required' => true]);
        $readsAndWrites = [
            static fn () => $publisher->show($admin, $provider),
            static fn () => $publisher->change($admin, $provider, 'tariffs', 1, 'publish', null),
            static fn () => $publisher->read($provider,
                DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->first(),
                'locations', ['offset' => 0, 'limit' => 1], null),
        ];
        foreach ($readsAndWrites as $action) {
            try {
                $action();
                $this->fail('Unreviewed CPO source was published/read under an old active credential.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }

        // A once-valid digest cannot survive natural expiry or owner change.
        DB::table('ocpi_partner_contract_reviews')->insert([
            'provider_id' => $provider->id, 'owner_id' => $owner->id,
            'reviewer_id' => $admin->id, 'revision' => 1,
            'connection_key' => $provider->connection_key,
            'counterparty_hash' => ContractCounterpartyIdentity::current($provider->connection_key),
            'decision' => 'review', 'reference_id' => 'SYNTHETIC-CPO-001',
            'evidence_sha256' => str_repeat('a', 64),
            'valid_until' => now('UTC')->addDay()->toDateString(),
            'idempotency_key' => 'cpo-review-fence-0001',
            'payload_hash' => str_repeat('b', 64),
            'reason_ciphertext' => Crypt::encryptString('Synthetic internal reviewer approval only'),
            'created_at' => now('UTC'),
        ]);
        $this->assertTrue($publisher->show($admin, $provider)['configured']);
        DB::table('ocpi_partner_contract_reviews')
            ->where('provider_id', $provider->id)
            ->update(['valid_until' => now('UTC')->subDay()->toDateString()]);
        foreach ($readsAndWrites as $action) {
            try {
                $action();
                $this->fail('Expired CPO review should fence even old negotiated sources.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        // Restore unexpired evidence and simulate a fully rebound, active
        // peer credential to another tenant: the previous owner's approval
        // must still not authorize CPO catalog access.
        DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->update(['valid_until' => now('UTC')->addDay()->toDateString()]);
        $replacement = User::factory()->create(['role' => 'vendor', 'account_status' => 'active']);
        $provider->update(['owner_id' => $replacement->id]);
        DB::table('ocpi_credential_links')->where('provider_id', $provider->id)
            ->update(['owner_id' => $replacement->id]);
        try {
            $publisher->show($admin, $provider->fresh());
            $this->fail('Previous owner review approved a different vendors CPO catalog.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $this->assertDatabaseCount('ocpi_cpo_catalog', 0);
        $this->assertDatabaseCount('ocpi_cpo_publication_operations', 0);
        $this->assertDatabaseCount('finance_payments', 0);
        Http::assertNothingSent();
    }
}
