<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): OcpiProvider
    {
        Http::preventStrayRequests();
        config(['ocpi.connections.boundary' => ['origin' => 'https://partner.example', 'authorization_token' => base64_encode('fake-contract-only')]]);

        return OcpiProvider::create(['owner_id' => User::factory()->create(['role' => 'vendor'])->id, 'name' => 'Authorization contract fixture', 'connection_key' => 'boundary', 'authorized' => true]);
    }

    private function envelope(array $data): array
    {
        return ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $data];
    }

    private function details(): array
    {
        return $this->envelope(['version' => '2.2.1', 'endpoints' => [['identifier' => 'locations', 'role' => 'SENDER', 'url' => 'https://partner.example/locations']]]);
    }

    private function versions(): array
    {
        return $this->envelope([['version' => '2.2.1', 'url' => 'https://partner.example/details']]);
    }

    public function test_authorization_changes_advance_hidden_generation_and_clear_capabilities(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations'], 'negotiated_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([false, true] as $index => $authorized) {
            $this->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => $authorized])->assertOk()->assertJsonPath('authorized', $authorized)->assertJsonMissingPath('authorization_revision')->assertJsonPath('version', null);
            $this->assertSame($index + 1, $p->fresh()->authorization_revision);
        }
        Http::assertNothingSent();
    }

    public function test_ambiguous_duplicate_version_is_rejected_without_selecting_last_endpoint(): void
    {
        $p = $this->provider();
        Http::fake(['*' => Http::response($this->envelope([['version' => '2.2.1', 'url' => 'https://partner.example/first'], ['version' => '2.2.1', 'url' => 'https://partner.example/second']]))]);
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Duplicate version was accepted');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('Ambiguous', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertNull($p->fresh()->version);
    }

    public function test_revocation_after_versions_response_prevents_next_outbound_request(): void
    {
        $p = $this->provider();
        Http::fake(function () use ($p) {
            DB::table('ocpi_providers')->where('id', $p->id)->update(['authorized' => false, 'authorization_revision' => 1]);

            return Http::response($this->versions());
        });
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Revoked operation sent another request');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('authorization changed', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertNull($p->fresh()->version);
    }

    public function test_reauthorization_during_final_http_response_cannot_commit_old_negotiation(): void
    {
        $p = $this->provider();
        Http::fake(function ($request) use ($p) {
            if (str_ends_with($request->url(), '/ocpi/versions')) {
                return Http::response($this->versions());
            }
            DB::table('ocpi_providers')->where('id', $p->id)->update(['authorized' => true, 'authorization_revision' => 2, 'version' => null, 'endpoints' => null]);

            return Http::response($this->details());
        });
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Old negotiation crossed a reauthorization boundary');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('stale negotiation', $error->getMessage());
        }
        $this->assertSame(2, $p->fresh()->authorization_revision);
        $this->assertNull($p->fresh()->version);
        $this->assertNull($p->fresh()->endpoints);
    }

    public function test_only_one_overlapping_negotiation_can_publish_from_the_same_generation(): void
    {
        $p = $this->provider();
        $nested = false;
        Http::fake(function ($request) use ($p, &$nested) {
            if (str_ends_with($request->url(), '/ocpi/versions')) {
                return Http::response($this->versions());
            }
            if (! $nested) {
                $nested = true;
                app(PartnerClient::class)->negotiate($p->fresh());
            }

            return Http::response($this->details());
        });
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Stale overlapping negotiation overwrote the winner');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('stale negotiation', $error->getMessage());
        }
        $this->assertSame(1, $p->fresh()->authorization_revision);
        $this->assertSame('2.2.1', $p->fresh()->version);
    }

    public function test_old_pull_cannot_ingest_after_reauthorization_even_when_version_matches(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1']);
        DB::table('ocpi_providers')->where('id', $p->id)->update(['authorization_revision' => 2]);
        $record = ['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'old', 'last_updated' => now()->subMinute()->toISOString(), 'publish' => true, 'address' => 'Fixture', 'city' => 'Fixture', 'country' => 'ARE', 'coordinates' => ['latitude' => '25', 'longitude' => '55']];
        try {
            app(RecordSynchronizer::class)->ingest($p, 'locations', [$record]);
            $this->fail('Stale pull was ingested');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('authorization changed', $error->getMessage());
        }
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_revocation_between_pages_does_not_forward_credentials_again(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        Http::fake(function () use ($p) {
            DB::table('ocpi_providers')->where('id', $p->id)->update(['authorized' => false, 'authorization_revision' => 1]);

            return Http::response($this->envelope([]), 200, ['Link' => '<https://partner.example/locations?page=2>; rel="next"']);
        });
        try {
            app(PartnerClient::class)->pull($p, 'locations');
            $this->fail('Revoked pagination continued');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('authorization changed', $error->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_provider_ownership_change_also_invalidates_inflight_request(): void
    {
        $p = $this->provider();
        $owner = User::factory()->create(['role' => 'vendor']);
        Http::fake(function () use ($p, $owner) {
            DB::table('ocpi_providers')->where('id', $p->id)->update(['owner_id' => $owner->id]);

            return Http::response($this->versions());
        });
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Previous owner continued negotiation');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('authorization changed', $error->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_generation_exhaustion_fails_closed_without_reenabling_or_outbound_http(): void
    {
        $p = $this->provider();
        $p->update(['authorization_revision' => PHP_INT_MAX, 'authorized' => false]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => true])->assertConflict();
        $this->assertFalse($p->fresh()->authorized);
        $this->assertSame(PHP_INT_MAX, $p->fresh()->authorization_revision);
        Http::assertNothingSent();
    }

    public function test_terminal_generation_can_still_be_revoked_but_never_reauthorized(): void
    {
        $p = $this->provider();
        $p->update(['authorization_revision' => PHP_INT_MAX, 'version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $stale = $p->fresh();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => false])->assertOk()->assertJsonPath('authorized', false)->assertJsonPath('version', null);
        $this->assertSame(PHP_INT_MAX, $p->fresh()->authorization_revision);
        $this->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => true])->assertConflict();
        try {
            app(PartnerClient::class)->pull($stale, 'locations');
            $this->fail('A revoked terminal-generation read was dispatched.');
        } catch (OcpiException $e) {
            $this->assertStringContainsString('authorization changed', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_non_testing_downgrade_preserves_existing_authorization_generations(): void
    {
        $p = $this->provider();
        $p->update(['authorization_revision' => 1]);
        $migration = require database_path('migrations/2026_11_04_230002_add_ocpi_authorization_revision.php');
        $this->app->detectEnvironment(fn () => 'local');
        try {
            $migration->down();
            $this->fail('Populated authorization boundary was discarded');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Preserve OCPI authorization generations', $error->getMessage());
            $this->assertSame(1, $p->fresh()->authorization_revision);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
