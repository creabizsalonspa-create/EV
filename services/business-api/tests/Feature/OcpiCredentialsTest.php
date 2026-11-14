<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\IncomingEnrollment;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OcpiCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private array $writes = [];

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['ocpi.connections.managed' => ['managed_credentials' => true, 'origin' => 'https://partner.example', 'authorization_token' => base64_encode('BOOTSTRAP-A-SYNTHETIC'), 'party_country' => 'NL', 'party_id' => 'ABC', 'party_role' => 'CPO'], 'ocpi.local_credentials' => ['url' => 'https://platform.example/api/roaming-protocol/versions', 'roles' => [['role' => 'EMSP', 'country_code' => 'AE', 'party_id' => 'EVX', 'business_details' => ['name' => 'Synthetic Crea-Biz']]]]]);
        $owner = User::factory()->create(['role' => 'vendor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $p = OcpiProvider::create(['owner_id' => $owner->id, 'name' => 'Managed synthetic peer', 'connection_key' => 'managed', 'authorized' => true]);

        return [$p, $admin, $owner, '/api/ocpi/providers/'.$p->id.'/credentials'];
    }

    private function envelope(mixed $data): array
    {
        return ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $data];
    }

    private function peer(string $token): array
    {
        return ['token' => $token, 'url' => 'https://partner.example/ocpi/versions', 'roles' => [['role' => 'CPO', 'country_code' => 'NL', 'party_id' => 'ABC', 'business_details' => ['name' => 'Synthetic peer']]]];
    }

    private function source(string $token = 'REMOTE-C-SYNTHETIC', bool $lost = false, ?callable $duringDiscovery = null, bool $wrongPeer = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($token, $lost, $duringDiscovery, $wrongPeer) {
            if (str_ends_with($r->url(), '/ocpi/versions')) {
                return Http::response($this->envelope([['version' => '2.2.1', 'url' => 'https://partner.example/ocpi/2.2.1']]));
            }
            if (str_ends_with($r->url(), '/ocpi/2.2.1')) {
                if ($duringDiscovery) {
                    $duringDiscovery();
                }

                return Http::response($this->envelope(['version' => '2.2.1', 'endpoints' => [['identifier' => 'credentials', 'role' => 'RECEIVER', 'url' => 'https://partner.example/ocpi/2.2.1/credentials']]]));
            }
            $this->writes[] = ['method' => $r->method(), 'body' => $r->data(), 'authorization' => $r->header('Authorization')[0]];
            if (isset($r->data()['token'])) {
                $headers = $this->protocol($r->data()['token']);
                $this->getJson('/api/roaming-protocol/versions', $headers)->assertOk();
                $this->getJson('/api/roaming-protocol/2.2.1', $headers)->assertOk();
                $this->getJson('/api/roaming-protocol/2.2.1/credentials', $headers)->assertUnauthorized();
            }
            if ($lost) {
                return Http::response(['private' => 'DO-NOT-EXPOSE'], 503);
            }
            $peer = $this->peer($token);
            if ($wrongPeer) {
                $peer['roles'][0]['party_id'] = 'BAD';
            }

            return Http::response($this->envelope($r->method() === 'DELETE' ? null : $peer));
        });
    }

    private function intent(string $action = 'register', string $key = 'first'): array
    {
        return ['action' => $action, 'reason' => 'Approved synthetic credentials exchange', 'idempotency_key' => $key];
    }

    private function protocol(string $token): array
    {
        return ['Authorization' => 'Token '.base64_encode($token)];
    }

    #[TestWith(['epoch'])]
    #[TestWith(['password'])]
    #[TestWith(['suspension'])]
    public function test_administrator_revocation_during_discovery_cannot_dispatch_a_stale_approval(string $change): void
    {
        [$provider, $admin, , $url] = $this->fixture();
        $this->source(duringDiscovery: function () use ($admin, $change) {
            if ($change === 'epoch') {
                DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
            } elseif ($change === 'password') {
                DB::table('users')->where('id', $admin->id)->update(['password' => Hash::make('New synthetic password only')]);
            } else {
                DB::table('users')->where('id', $admin->id)->update(['account_status' => 'suspended']);
            }
        });
        $reply = $this->actingAs($admin)->postJson($url, $this->intent());
        if ($change === 'suspension') {
            $reply->assertForbidden();
        } else {
            $reply->assertConflict();
        }
        $this->assertSame([], $this->writes);
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
        $this->assertDatabaseCount('ocpi_credential_links', 0);
        $this->assertNull($provider->fresh()->version);
    }

    public function test_approval_revoked_after_claim_is_quarantined_and_fresh_receipt_recovery_never_resends(): void
    {
        [$provider, $admin, , $url] = $this->fixture();
        $this->source();
        $revoked = false;
        DB::listen(function ($query) use ($admin, &$revoked) {
            if (! $revoked && str_contains($query->sql, 'insert into "ocpi_credential_operations"')) {
                $revoked = true;
                DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
            }
        });
        $receipt = $this->actingAs($admin)->postJson($url, $this->intent())->assertOk()->assertJsonPath('state', 'outcome_unknown')->json();
        $this->assertTrue($revoked);
        $this->assertSame([], $this->writes);
        $this->assertNull(DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->value('local_token_digest'));
        $current = $admin->fresh();
        $this->actingAs($current)->withSession(['account_auth_epoch' => $current->auth_epoch])->postJson($url, $this->intent())->assertOk()->assertJsonPath('id', $receipt['id'])->assertJsonPath('state', 'outcome_unknown');
        $this->assertSame([], $this->writes);
        $this->assertDatabaseCount('ocpi_credential_operations', 1);
    }

    public function test_incoming_preparation_cannot_borrow_a_new_admin_epoch_from_a_stale_request(): void
    {
        [$provider, $admin] = $this->fixture();
        config(['ocpi.connections.managed.registration_direction' => 'incoming', 'ocpi.connections.managed.inbound_authorization_token' => base64_encode('SYNTHETIC-INCOMING-BOOTSTRAP')]);
        $snapshot = clone $admin;
        DB::table('users')->where('id', $admin->id)->increment('auth_epoch');
        try {
            app(IncomingEnrollment::class)->prepare($provider, $admin->id, $this->intent('prepare_incoming'), $snapshot);
            $this->fail('A stale administrator prepared new enrollment authority.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('ocpi_credential_links', 0);
        $this->assertDatabaseCount('ocpi_credential_operations', 0);
        Http::assertNothingSent();
    }

    public function test_suspended_owner_cannot_rotate_but_admin_can_still_unregister_the_peer(): void
    {
        [$provider, $admin, $owner, $url] = $this->fixture();
        $this->source();
        $this->actingAs($admin)->postJson($url, $this->intent())->assertOk();
        $owner->forceFill(['account_status' => 'suspended'])->save();
        $this->postJson($url, $this->intent('rotate', 'blocked-rotate'))->assertConflict();
        $this->postJson($url, $this->intent('unregister', 'safe-unregister'))->assertOk()->assertJsonPath('state', 'acknowledged');
        $this->assertSame('unregistered', DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->value('state'));
        $this->assertNull(DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->value('local_token_digest'));
        $this->assertNull($provider->fresh()->version);
        $this->assertSame('DELETE', $this->writes[1]['method']);
    }

    public function test_registered_credentials_are_private_one_shot_and_not_charging_or_billing_authority(): void
    {
        [$p, $admin, $owner, $url] = $this->fixture();
        $this->actingAs($owner)->postJson($url, $this->intent())->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'vendor']))->getJson($url)->assertNotFound();
        Http::assertNothingSent();
        $this->source();
        $this->actingAs($admin)->postJson($url, $this->intent())->assertOk()->assertJsonPath('state', 'acknowledged')->assertJsonPath('charging_commands_enabled', false)->assertJsonPath('billing_eligible', false);
        $this->postJson($url, $this->intent())->assertOk();
        $this->postJson($url, $this->intent('rotate'))->assertConflict();
        $this->assertCount(1, $this->writes);
        $this->assertSame('Token '.base64_encode('BOOTSTRAP-A-SYNTHETIC'), $this->writes[0]['authorization']);
        $local = $this->writes[0]['body']['token'];
        $this->assertSame(64, strlen($local));
        $this->assertSame(base64_encode('REMOTE-C-SYNTHETIC'), app(PartnerClient::class)->connection($p->fresh())['authorization_token']);
        $status = $this->getJson($url)->assertOk()->assertJsonPath('available_actions', ['rotate', 'unregister']);
        $this->assertStringNotContainsString($local, $status->getContent());
        $this->assertStringNotContainsString('REMOTE-C-SYNTHETIC', $status->getContent());
        $link = DB::table('ocpi_credential_links')->first();
        $this->assertStringNotContainsString($local, $link->local_token_ciphertext);
        $this->assertSame(hash('sha256', $local), $link->local_token_digest);
        $this->getJson('/api/roaming-protocol/versions')->assertUnauthorized(); // Cookie login is not protocol authority.
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($local))->assertOk()->assertJsonPath('data.0.version', '2.2.1');
        $this->getJson('/api/roaming-protocol/2.2.1/credentials', $this->protocol($local))->assertOk()->assertJsonPath('data.token', $local);
        $this->assertDatabaseCount('charger_commands', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_rotation_replaces_both_tokens_and_unregister_prevents_fallback(): void
    {
        [$p, $admin, , $url] = $this->fixture();
        $this->source();
        $this->actingAs($admin)->postJson($url, $this->intent())->assertOk();
        $old = $this->writes[0]['body']['token'];
        $this->source('REMOTE-C2-SYNTHETIC');
        $this->postJson($url, $this->intent('rotate', 'rotate'))->assertOk()->assertJsonPath('state', 'acknowledged');
        $new = $this->writes[1]['body']['token'];
        $this->assertNotSame($old, $new);
        $this->assertSame('Token '.base64_encode('REMOTE-C-SYNTHETIC'), $this->writes[1]['authorization']);
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($old))->assertUnauthorized();
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($new))->assertOk();
        $this->source();
        $this->postJson($url, $this->intent('unregister', 'delete'))->assertOk()->assertJsonPath('state', 'acknowledged');
        $this->assertSame('DELETE', $this->writes[2]['method']);
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($new))->assertUnauthorized();
        $this->postJson($url, $this->intent('register', 'again'))->assertConflict();
        $this->assertCount(3, $this->writes);
        $this->expectException(OcpiException::class);
        app(PartnerClient::class)->connection($p->fresh());
    }

    public function test_authenticated_peer_rotation_and_revocation_fence_old_credentials(): void
    {
        [$p, $admin, , $url] = $this->fixture();
        $this->source();
        $this->actingAs($admin)->postJson($url, $this->intent())->assertOk();
        $old = $this->writes[0]['body']['token'];
        $body = $this->peer('PEER-ROTATED-C');
        $reply = $this->putJson('/api/roaming-protocol/2.2.1/credentials', $body, $this->protocol($old))->assertOk();
        $new = $reply->json('data.token');
        $this->assertNotSame($old, $new);
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($old))->assertUnauthorized();
        $this->assertSame(base64_encode('PEER-ROTATED-C'), app(PartnerClient::class)->connection($p->fresh())['authorization_token']);
        $this->deleteJson('/api/roaming-protocol/2.2.1/credentials', [], $this->protocol($new))->assertOk();
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($new))->assertUnauthorized();
        $this->assertFalse($p->fresh()->authorized);
        $this->assertDatabaseHas('ocpi_credential_operations', ['action' => 'peer_revoke', 'actor_id' => null, 'state' => 'acknowledged']);
    }

    public function test_unknown_delivery_retains_exact_capsule_and_never_resends_or_falls_back(): void
    {
        [$p, $admin, , $url] = $this->fixture();
        $this->source(lost: true);
        $reply = $this->actingAs($admin)->postJson($url, $this->intent())->assertOk()->assertJsonPath('state', 'outcome_unknown');
        $this->assertStringNotContainsString('DO-NOT-EXPOSE', $reply->getContent());
        $this->postJson($url, $this->intent())->assertOk()->assertJsonPath('state', 'outcome_unknown');
        $this->postJson($url, $this->intent('register', 'new-key'))->assertConflict();
        $this->assertCount(1, $this->writes);
        Http::assertSentCount(3);
        $op = DB::table('ocpi_credential_operations')->first();
        $capsule = Crypt::decryptString($op->source_payload_ciphertext);
        $this->assertSame($op->source_payload_hash, hash('sha256', $capsule));
        $this->assertSame($this->writes[0]['body'], json_decode($capsule, true)['body']);
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($this->writes[0]['body']['token']))->assertUnauthorized();
        $this->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => false])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('state', 'revoked');
    }

    public function test_wrong_peer_identity_is_not_installed_and_discovery_owner_change_prevents_post(): void
    {
        [$p, $admin, , $url] = $this->fixture();
        $this->source(wrongPeer: true);
        $this->actingAs($admin)->postJson($url, $this->intent())->assertOk()->assertJsonPath('state', 'outcome_unknown');
        $this->assertDatabaseHas('ocpi_credential_links', ['remote_token_ciphertext' => null, 'local_token_digest' => null]);
        $this->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => false])->assertOk();
        $this->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => true])->assertOk();
        config(['ocpi.connections.managed.authorization_token' => base64_encode('NEW-BOOTSTRAP')]);
        $this->source(duringDiscovery: function () use ($p) {
            $p->update(['owner_id' => User::factory()->create(['role' => 'vendor'])->id]);
        });
        $this->postJson($url, $this->intent('register', 'owner-change'))->assertConflict();
        $this->assertCount(1, $this->writes);
    }

    public function test_unregister_remains_available_at_terminal_generations(): void
    {
        [$p, $admin, , $url] = $this->fixture();
        $this->source();
        $this->actingAs($admin)->postJson($url, $this->intent())->assertOk();
        DB::table('ocpi_credential_links')->where('provider_id', $p->id)->update(['generation' => 2147483647]);
        $p->refresh()->update(['authorization_revision' => PHP_INT_MAX]);
        $this->source();
        $this->postJson($url, $this->intent('unregister', 'terminal-delete'))->assertOk()->assertJsonPath('state', 'acknowledged');
        $this->assertDatabaseHas('ocpi_credential_links', ['state' => 'unregistered', 'local_token_digest' => null]);
        $this->assertSame(PHP_INT_MAX, $p->fresh()->authorization_revision);
        $this->assertCount(2, $this->writes);
    }

    private function incomingFixture(): array
    {
        $f = $this->fixture();
        config(['ocpi.connections.managed.registration_direction' => 'incoming', 'ocpi.connections.managed.authorization_token' => null, 'ocpi.connections.managed.inbound_authorization_token' => base64_encode('INCOMING-A-SYNTHETIC')]);

        return $f;
    }

    public function test_preapproved_incoming_enrollment_consumes_bootstrap_and_retains_its_history_through_reciprocal_rotation(): void
    {
        [$p, $admin, $owner, $url] = $this->incomingFixture();
        $intent = $this->intent('prepare_incoming');
        $this->actingAs($owner)->postJson($url, $intent)->assertForbidden();
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('available_actions', ['prepare_incoming']);
        $this->postJson($url, $intent)->assertOk()->assertJsonPath('state', 'awaiting_peer');
        $this->postJson($url, $intent)->assertOk();
        Http::assertNothingSent();
        $headers = $this->protocol('INCOMING-A-SYNTHETIC');
        $this->getJson('/api/roaming-protocol/versions', $headers)->assertOk();
        $this->getJson('/api/roaming-protocol/2.2.1/credentials', $headers)->assertUnauthorized();
        $this->putJson('/api/roaming-protocol/2.2.1/credentials', $this->peer('B'), $headers)->assertUnauthorized();
        $this->source();
        $reply = $this->postJson('/api/roaming-protocol/2.2.1/credentials', $this->peer('INCOMING-PEER-B'), $headers)->assertCreated();
        $token = $reply->json('data.token');
        $this->assertSame(64, strlen($token));
        $this->assertSame([], $this->writes); // Only read-only peer discovery is sent.
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Token '.base64_encode('INCOMING-PEER-B')));
        $this->postJson('/api/roaming-protocol/2.2.1/credentials', $this->peer('INCOMING-PEER-B'), $headers)->assertUnauthorized();
        $this->getJson('/api/roaming-protocol/versions', $headers)->assertUnauthorized();
        $this->getJson('/api/roaming-protocol/versions', $this->protocol($token))->assertOk();
        $this->postJson('/api/roaming-protocol/2.2.1/credentials', $this->peer('INCOMING-PEER-B'), $this->protocol($token))->assertStatus(405);
        $this->assertSame(base64_encode('INCOMING-PEER-B'), app(PartnerClient::class)->connection($p->fresh())['authorization_token']);
        $this->assertDatabaseHas('ocpi_credential_operations', ['action' => 'peer_register', 'state' => 'acknowledged']);
        $this->source('RECIPROCAL-C');
        $this->postJson($url, $this->intent('rotate', 'reciprocal'))->assertOk()->assertJsonPath('state', 'acknowledged');
        $this->assertSame(hash('sha256', base64_encode('INCOMING-A-SYNTHETIC')), DB::table('ocpi_credential_links')->value('bootstrap_hash'));
        $this->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => false])->assertOk();
        $this->patchJson('/api/ocpi/providers/'.$p->id.'/authorization', ['authorized' => true])->assertOk();
        $this->postJson($url, $this->intent('prepare_incoming', 'cannot-reuse'))->assertConflict();
    }

    public function test_incoming_bootstrap_expires_and_replacement_requires_fresh_server_authority(): void
    {
        [$p, $admin, , $url] = $this->incomingFixture();
        $this->actingAs($admin)->postJson($url, $this->intent('prepare_incoming'))->assertOk();
        $invalid = $this->peer('INCOMING-B');
        $invalid['roles'][0]['party_id'] = 'BAD';
        $this->postJson('/api/roaming-protocol/2.2.1/credentials', $invalid, $this->protocol('INCOMING-A-SYNTHETIC'))->assertStatus(400);
        Http::assertNothingSent();
        $this->travel(31)->minutes();
        $this->getJson('/api/roaming-protocol/versions', $this->protocol('INCOMING-A-SYNTHETIC'))->assertUnauthorized();
        $this->getJson($url)->assertOk()->assertJsonPath('state', 'enrollment_expired')->assertJsonPath('available_actions', []);
        $this->postJson($url, $this->intent('prepare_incoming', 'expired-reuse'))->assertConflict();
        config(['ocpi.connections.managed.inbound_authorization_token' => base64_encode('FRESH-INCOMING-A')]);
        $this->postJson($url, $this->intent('prepare_incoming', 'fresh'))->assertOk();
        $this->getJson('/api/roaming-protocol/versions', $this->protocol('FRESH-INCOMING-A'))->assertOk();
        $this->assertDatabaseHas('ocpi_credential_operations', ['generation' => 1, 'action' => 'prepare_incoming', 'state' => 'superseded']);
        Http::assertNothingSent();
    }

    public function test_existing_outbound_credentials_keep_their_published_fingerprint_after_upgrade(): void
    {
        [$p] = $this->fixture();
        // Golden fingerprint from the prior39e30cc configuration, not generated by the upgraded service.
        DB::table('ocpi_credential_links')->insert(['provider_id' => $p->id, 'owner_id' => $p->owner_id, 'generation' => 1, 'state' => 'active', 'configuration_hash' => '5a8f4800292ee9d84bba30127815cd982630a652aab3fa84018dcfb6f904e21e', 'bootstrap_hash' => hash('sha256', base64_encode('BOOTSTRAP-A-SYNTHETIC')), 'local_token_digest' => hash('sha256', 'OLD-LOCAL'), 'local_token_ciphertext' => Crypt::encryptString('OLD-LOCAL'), 'remote_token_ciphertext' => Crypt::encryptString('OLD-REMOTE'), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(base64_encode('OLD-REMOTE'), app(PartnerClient::class)->connection($p)['authorization_token']);
        Http::assertNothingSent();
    }
}
