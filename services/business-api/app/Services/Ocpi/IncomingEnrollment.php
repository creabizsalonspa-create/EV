<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Preapproved, expiring bootstrap A -> independently validated peer B -> local C. */
class IncomingEnrollment
{
    public function prepare(OcpiProvider $p, int $actor, array $v, ?User $requestActor = null): array
    {
        $approval = clone ($requestActor ?? User::findOrFail($actor));
        abort_unless($approval->id === $actor, 403);
        app(AdminCredentialApproval::class)->current($approval);
        $exchange = app(CredentialExchange::class);
        [$c] = $exchange->configuration($p);
        abort_unless(($c['registration_direction'] ?? 'outbound') === 'incoming', 409, 'Server configuration must approve incoming enrollment.');
        $encoded = (string) ($c['inbound_authorization_token'] ?? '');
        $raw = base64_decode($encoded, true);
        abort_unless(is_string($raw) && preg_match('/^[\x21-\x7e]{1,64}$/D', $raw) && base64_encode($raw) === $encoded, 409, 'A valid server-provisioned incoming bootstrap token is required.');
        $payloadHash = hash('sha256', json_encode([$v['action'], $v['reason']], JSON_THROW_ON_ERROR));
        $configuration = $exchange->configurationHash($p);

        return DB::transaction(function () use ($p, $actor, $v, $exchange, $encoded, $raw, $payloadHash, $configuration, $approval) {
            app(AdminCredentialApproval::class)->current($approval, true);
            $current = OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->current($p, $current, $configuration);
            $old = DB::table('ocpi_credential_operations')->where('provider_id', $p->id)->where('idempotency_key', $v['idempotency_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $payloadHash), 409);

                return $exchange->operation($old);
            }
            $link = DB::table('ocpi_credential_links')->where('provider_id', $p->id)->lockForUpdate()->first();
            abort_unless(! $link || in_array($link->state, ['revoked', 'unregistered', 'awaiting_registration'], true), 409, 'Active or uncertain credentials must not be overwritten.');
            abort_unless(! $link || ! hash_equals($link->bootstrap_hash, hash('sha256', $encoded)), 409, 'A fresh server-provisioned bootstrap token is required.');
            DB::table('ocpi_credential_operations')->where('provider_id', $p->id)->where('action', 'prepare_incoming')->where('state', 'awaiting_peer')->update(['state' => 'superseded', 'completed_at' => now(), 'updated_at' => now()]);
            $generation = (int) ($link->generation ?? 0) + 1;
            abort_unless($generation <= 2147483647, 409, 'Credential generation exhausted.');
            $expires = now()->addMinutes(30)->startOfSecond();
            $capsule = json_encode(['configuration_hash' => $configuration, 'expires_at' => $expires->toISOString()], JSON_THROW_ON_ERROR);
            $id = DB::table('ocpi_credential_operations')->insertGetId(['provider_id' => $p->id, 'actor_id' => $actor, 'generation' => $generation, 'action' => 'prepare_incoming', 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $payloadHash, 'reason' => Crypt::encryptString($v['reason']), 'source_payload_ciphertext' => Crypt::encryptString($capsule), 'source_payload_hash' => hash('sha256', $capsule), 'state' => 'awaiting_peer', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ocpi_credential_links')->updateOrInsert(['provider_id' => $p->id], ['owner_id' => $p->owner_id, 'generation' => $generation, 'state' => 'awaiting_registration', 'configuration_hash' => $configuration, 'bootstrap_hash' => hash('sha256', $encoded), 'bootstrap_expires_at' => $expires, 'local_token_digest' => hash('sha256', $raw), 'local_token_ciphertext' => null, 'remote_token_ciphertext' => null, 'exchanged_at' => null, 'created_at' => $link->created_at ?? now(), 'updated_at' => now()]);
            $current->update(['version' => null, 'endpoints' => null, 'negotiated_at' => null, 'authorization_revision' => $current->authorization_revision + 1]);
            DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'ocpi.incoming_enrollment_prepared', 'subject' => (string) $p->id, 'created_at' => now()]);

            return $exchange->operation(DB::table('ocpi_credential_operations')->where('id', $id)->first());
        }, 3);
    }

    public function accept(OcpiProvider $p, object $link, array $body): array
    {
        $exchange = app(CredentialExchange::class);
        $peer = $exchange->validatePeer($p, $body);
        abort_unless($link->state === 'awaiting_registration' && $link->bootstrap_expires_at && CarbonImmutable::parse($link->bootstrap_expires_at)->isFuture(), 401);
        // Read-only discovery with the peer's newly offered token B. It cannot
        // modify the peer and is fenced again before local activation.
        $exchange->endpoint($p, base64_encode($peer['token']));

        return DB::transaction(function () use ($p, $link, $body, $peer, $exchange) {
            $current = OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->current($p, $current, $link->configuration_hash);
            $active = DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
            abort_unless($active->state === 'awaiting_registration' && $active->generation === $link->generation && $active->local_token_digest === $link->local_token_digest && $active->bootstrap_expires_at && CarbonImmutable::parse($active->bootstrap_expires_at)->isFuture(), 401);
            $prepared = DB::table('ocpi_credential_operations')->where('provider_id', $p->id)->where('generation', $active->generation)->where('action', 'prepare_incoming')->where('state', 'awaiting_peer')->lockForUpdate()->firstOrFail();
            $proof = Crypt::decryptString($prepared->source_payload_ciphertext);
            abort_unless(hash_equals($prepared->source_payload_hash, hash('sha256', $proof)) && (json_decode($proof, true, flags: JSON_THROW_ON_ERROR)['configuration_hash'] ?? null) === $active->configuration_hash, 409);
            $evidence = json_decode($proof, true, flags: JSON_THROW_ON_ERROR);
            abort_unless(isset($evidence['expires_at']) && CarbonImmutable::parse($evidence['expires_at'])->isFuture() && CarbonImmutable::parse($evidence['expires_at'])->equalTo(CarbonImmutable::parse($active->bootstrap_expires_at)), 409);
            $token = bin2hex(random_bytes(32));
            DB::table('ocpi_credential_links')->where('id', $active->id)->update(['state' => 'active', 'bootstrap_expires_at' => null, 'local_token_digest' => hash('sha256', $token), 'local_token_ciphertext' => Crypt::encryptString($token), 'remote_token_ciphertext' => Crypt::encryptString($peer['token']), 'exchanged_at' => now(), 'updated_at' => now()]);
            $current->update(['version' => null, 'endpoints' => null, 'negotiated_at' => null, 'authorization_revision' => $current->authorization_revision + 1]);
            DB::table('ocpi_credential_operations')->where('id', $prepared->id)->update(['state' => 'acknowledged', 'completed_at' => now(), 'updated_at' => now()]);
            $capsule = json_encode($body, JSON_THROW_ON_ERROR);
            DB::table('ocpi_credential_operations')->insert(['provider_id' => $p->id, 'actor_id' => null, 'generation' => $active->generation, 'action' => 'peer_register', 'idempotency_key' => 'incoming-'.$prepared->id, 'payload_hash' => hash('sha256', $capsule), 'reason' => Crypt::encryptString('Authenticated preapproved peer enrollment'), 'source_payload_ciphertext' => Crypt::encryptString($capsule), 'source_payload_hash' => hash('sha256', $capsule), 'state' => 'acknowledged', 'attempted_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $exchange->localPayload($token);
        }, 3);
    }

    private function current(OcpiProvider $original, OcpiProvider $current, string $configuration): void
    {
        abort_unless($current->authorized && $current->owner_id === $original->owner_id && $current->connection_key === $original->connection_key && $current->authorization_revision === $original->authorization_revision && $current->authorization_revision < PHP_INT_MAX && User::whereKey($current->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists() && hash_equals($configuration, app(CredentialExchange::class)->configurationHash($current)), 409, 'Partner enrollment authority changed.');
    }
}
