<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\RecordSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
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

    public function test_native_partner_capabilities_expose_only_current_readable_module_names(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/private-location', 'commands' => 'https://partner.example/private-command'], 'negotiated_at' => now()]);
        $reply = $this->actingAs(User::findOrFail($p->owner_id))->getJson('/api/ocpi/providers')->assertOk()->assertJsonPath('data.0.readable_modules', ['locations'])->assertJsonPath('data.0.commands_enabled', false)->assertJsonPath('data.0.billing_eligible', false)->assertJsonMissingPath('data.0.endpoints')->assertJsonMissingPath('data.0.connection_key');
        $this->assertStringNotContainsString('private-location', $reply->getContent());
        $p->update(['authorized' => false]);
        $this->getJson('/api/ocpi/providers')->assertOk()->assertJsonPath('data.0.readable_modules', []);
        Http::assertNothingSent();
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

    #[TestWith(['versions_continuation', 1])]
    #[TestWith(['versions_oversized', 1])]
    #[TestWith(['versions_object', 1])]
    #[TestWith(['versions_scalar_entry', 1])]
    #[TestWith(['details_continuation', 2])]
    #[TestWith(['details_mapping', 2])]
    #[TestWith(['details_numeric_object', 2])]
    #[TestWith(['details_array_entry', 2])]
    #[TestWith(['details_oversized', 2])]
    public function test_negotiation_rejects_incomplete_or_unbounded_capability_advertisements(string $kind, int $requests): void
    {
        $p = $this->provider();
        $versions = $this->versions();
        $details = $this->details();
        $versionHeaders = [];
        $detailHeaders = [];
        if ($kind === 'versions_continuation') {
            $versionHeaders['Link'] = '<?page=2>; type="application/json"; rel="next"';
        } elseif ($kind === 'versions_oversized') {
            for ($i = 0; $i < 20; $i++) {
                $versions['data'][] = ['version' => '1.'.$i, 'url' => 'https://partner.example/old-'.$i];
            }
        } elseif ($kind === 'versions_object') {
            $versions['data'] = (object) $versions['data'];
        } elseif ($kind === 'versions_scalar_entry') {
            $versions['data'][] = 'invalid-version';
        } elseif ($kind === 'details_continuation') {
            $detailHeaders['Link'] = '<?page=2>; rel="next"';
        } elseif ($kind === 'details_mapping') {
            $details['data']['endpoints'] = ['locations' => $details['data']['endpoints'][0]];
        } elseif ($kind === 'details_numeric_object') {
            $details['data']['endpoints'] = (object) $details['data']['endpoints'];
        } elseif ($kind === 'details_array_entry') {
            $details['data']['endpoints'][] = [];
        } elseif ($kind === 'details_oversized') {
            for ($i = 0; $i < 30; $i++) {
                $details['data']['endpoints'][] = ['identifier' => 'extension'.$i, 'role' => 'SENDER', 'url' => 'https://partner.example/extension'.$i];
            }
        }
        Http::fake([
            'https://partner.example/ocpi/versions' => Http::response($versions, 200, $versionHeaders),
            'https://partner.example/details' => Http::response($details, 200, $detailHeaders),
        ]);
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Incomplete or unbounded capability advertisement was published.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('Malformed', $error->getMessage());
        }
        Http::assertSentCount($requests);
        $this->assertNull($p->fresh()->version);
        $this->assertNull($p->fresh()->endpoints);
        $this->assertSame(0, $p->fresh()->authorization_revision);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_connection_replacement_during_versions_cannot_dispatch_second_read(): void
    {
        $p = $this->provider();
        Http::fake(function () {
            config(['ocpi.connections.boundary.origin' => 'https://replacement.example',
                'ocpi.connections.boundary.authorization_token' => base64_encode('replacement-synthetic')]);

            return Http::response($this->envelope([['version' => '2.2.1', 'url' => 'https://replacement.example/details']]));
        });
        try {
            app(PartnerClient::class)->negotiate($p);
            $this->fail('Changed connection continued negotiation.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('connection authority changed', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertNull($p->fresh()->version);
        $this->assertSame(0, $p->fresh()->authorization_revision);
    }

    public function test_same_origin_token_replacement_discards_incomplete_catalog_read(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        Http::fake(function () {
            config(['ocpi.connections.boundary.authorization_token' => base64_encode('replacement-synthetic')]);

            return Http::response($this->envelope([]), 200, ['Link' => '<https://partner.example/locations?page=2>; rel="next"']);
        });
        try {
            app(PartnerClient::class)->pull($p, 'locations');
            $this->fail('Changed token continued catalog pagination.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('connection authority changed', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_final_catalog_page_is_discarded_if_connection_changes_during_response(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $record = ['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'source', 'last_updated' => now()->toISOString(),
            'publish' => true, 'address' => 'Fixture', 'city' => 'Fixture', 'country' => 'ARE',
            'coordinates' => ['latitude' => '25', 'longitude' => '55']];
        Http::fake(function () use ($record) {
            config(['ocpi.connections.boundary.authorization_token' => base64_encode('replacement-synthetic')]);

            return Http::response($this->envelope([$record]));
        });
        try {
            $records = app(PartnerClient::class)->pull($p, 'locations');
            app(RecordSynchronizer::class)->ingest($p, 'locations', $records);
            $this->fail('Changed connection returned a final catalog page.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('connection authority changed', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_managed_credential_replacement_at_same_generation_stops_pagination(): void
    {
        $p = $this->provider();
        config(['ocpi.connections.boundary.managed_credentials' => true,
            'ocpi.connections.boundary.party_country' => 'NL', 'ocpi.connections.boundary.party_id' => 'ABC',
            'ocpi.connections.boundary.party_role' => 'CPO',
            'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions',
                'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX',
                    'business_details' => ['name' => 'Synthetic platform']]]]]);
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $id = DB::table('ocpi_credential_links')->insertGetId([
            'provider_id' => $p->id, 'owner_id' => $p->owner_id, 'generation' => 1, 'state' => 'active',
            'configuration_hash' => app(CredentialExchange::class)->configurationHash($p),
            'bootstrap_hash' => hash('sha256', 'synthetic-bootstrap'),
            'local_token_digest' => hash('sha256', 'synthetic-local'),
            'local_token_ciphertext' => Crypt::encryptString('synthetic-local'),
            'remote_token_ciphertext' => Crypt::encryptString('synthetic-remote'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(function ($request) use ($id) {
            $this->assertSame('Token '.base64_encode('synthetic-remote'), $request->header('Authorization')[0]);
            DB::table('ocpi_credential_links')->where('id', $id)->update([
                'remote_token_ciphertext' => Crypt::encryptString('synthetic-replacement'),
            ]);

            return Http::response($this->envelope([]), 200, ['Link' => '<https://partner.example/locations?page=2>; rel="next"']);
        });
        try {
            app(PartnerClient::class)->pull($p, 'locations');
            $this->fail('Changed managed authority continued pagination.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('connection authority changed', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_configuration_change_during_manual_ingestion_rolls_back_whole_batch(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $record = ['country_code' => 'AE', 'party_id' => 'TST', 'id' => 'source', 'last_updated' => now()->toISOString(),
            'publish' => true, 'address' => 'Fixture', 'city' => 'Fixture', 'country' => 'ARE',
            'coordinates' => ['latitude' => '25', 'longitude' => '55']];
        Http::fake(fn () => Http::response($this->envelope([$record])));
        $changed = false;
        DB::listen(function ($query) use (&$changed) {
            if (! $changed && str_starts_with($query->sql, 'insert into "ocpi_records"')) {
                $changed = true;
                config(['ocpi.connections.boundary.authorization_token' => base64_encode('replacement-synthetic')]);
            }
        });
        $this->actingAs(User::findOrFail($p->owner_id))->postJson('/api/ocpi/providers/'.$p->id.'/sync/locations')
            ->assertConflict();
        $this->assertTrue($changed);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertNull($p->fresh()->last_synced_at);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_same_origin_pagination_cannot_switch_to_another_module(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations', 'sessions' => 'https://partner.example/sessions']]);
        Http::fake(fn () => Http::response($this->envelope([]), 200, ['Link' => '<https://partner.example/sessions?page=2>; rel="next"']));
        try {
            app(PartnerClient::class)->pull($p, 'locations');
            $this->fail('Catalog pagination switched modules.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('negotiated module path', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_query_relative_pagination_keeps_the_negotiated_module(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        Http::fake([
            'https://partner.example/locations' => Http::response($this->envelope([['id' => 'one']]), 200, ['Link' => '<?page=2>; rel="next"']),
            'https://partner.example/locations?page=2' => Http::response($this->envelope([['id' => 'two']])),
        ]);
        $this->assertSame(['one', 'two'], array_column(app(PartnerClient::class)->pull($p, 'locations'), 'id'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    #[TestWith(['{}'])]
    #[TestWith(['{"0":{"id":"one"}}'])]
    #[TestWith(['[[]]'])]
    #[TestWith(['[null]'])]
    public function test_invalid_collection_shape_cannot_finish_or_continue_a_catalog_pull(string $invalid): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $body = '{"status_code":1000,"timestamp":'.json_encode(now()->toISOString(), JSON_THROW_ON_ERROR).',"data":'.$invalid.'}';
        Http::fake([
            'https://partner.example/locations' => Http::response($this->envelope([['id' => 'one']]), 200, ['Link' => '<?page=2>; rel="next"']),
            'https://partner.example/locations?page=2' => Http::response($body, 200, ['Content-Type' => 'application/json', 'Link' => '<?page=3>; rel="next"']),
        ]);
        try {
            app(PartnerClient::class)->pull($p, 'locations');
            $this->fail('A malformed source collection was treated as a valid catalog page.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('Malformed', $error->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertNull($p->fresh()->last_synced_at);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_endpoint_replacement_without_revision_change_discards_current_read(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        Http::fake(function () use ($p) {
            $p->fresh()->update(['endpoints' => ['locations' => 'https://partner.example/changed']]);

            return Http::response($this->envelope([]));
        });
        try {
            app(PartnerClient::class)->pull($p, 'locations');
            $this->fail('Read accepted after negotiated endpoint changed.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('connection authority changed', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_manual_sync_account_epoch_change_prevents_second_page(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $actor = User::factory()->create(['role' => 'admin']);
        Http::fake(function () use ($actor) {
            DB::table('users')->where('id', $actor->id)->increment('auth_epoch');

            return Http::response($this->envelope([]), 200, ['Link' => '<https://partner.example/locations?page=2>; rel="next"']);
        });
        $this->actingAs($actor)->postJson('/api/ocpi/providers/'.$p->id.'/sync/locations')->assertConflict();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_stale_negotiated_endpoint_snapshot_is_rejected_before_transport(): void
    {
        $p = $this->provider();
        $p->update(['version' => '2.2.1', 'endpoints' => ['locations' => 'https://partner.example/locations']]);
        $old = $p->fresh();
        $p->update(['endpoints' => ['locations' => 'https://partner.example/replacement']]);
        try {
            app(PartnerClient::class)->pull($old, 'locations');
            $this->fail('Stale endpoint dispatched.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('connection authority changed', $error->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_records', 0);
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
