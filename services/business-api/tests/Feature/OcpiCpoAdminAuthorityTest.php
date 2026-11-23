<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CpoCatalogPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
