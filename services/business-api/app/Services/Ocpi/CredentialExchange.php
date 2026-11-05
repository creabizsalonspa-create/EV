<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** One-shot OCPI2.2.1 credentials exchange. Tokens never enter application responses or logs. */
class CredentialExchange
{
    public function configuration(OcpiProvider $p): array
    {
        $c = config('ocpi.connections.'.$p->connection_key);
        $local = config('ocpi.local_credentials');
        abort_unless(is_array($c) && ($c['managed_credentials'] ?? false) === true && is_array($local), 409, 'Managed OCPI credentials are not configured.');
        abort_unless(preg_match('/^[A-Z]{2}$/D', $c['party_country'] ?? '') && preg_match('/^[A-Z0-9]{3}$/D', $c['party_id'] ?? '') && in_array($c['party_role'] ?? null, ['CPO', 'EMSP'], true), 409, 'Approved peer identity is required.');
        $this->validateLocal($local);
        app(PartnerClient::class)->connection($p, true);

        return [$c, $local];
    }

    private function validateLocal(array $local): void
    {
        $u = parse_url($local['url'] ?? '');
        abort_unless($u && ($u['scheme'] ?? null) === 'https' && isset($u['host']) && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $u['host']) && ! preg_match('/(?:^|\.)(?:localhost|local|internal|invalid)$/D', $u['host']) && ! isset($u['user']) && ! isset($u['pass']) && ! isset($u['query']) && ! isset($u['fragment']) && ($u['port'] ?? 443) === 443 && ($u['path'] ?? '') === '/api/roaming-protocol/versions' && is_array($local['roles'] ?? null) && array_is_list($local['roles']) && count($local['roles']) === 1, 409, 'Approved public OCPI versions URL and one local role are required.');
        $r = $local['roles'][0];
        abort_unless(is_array($r) && preg_match('/^[A-Z]{2}$/D', $r['country_code'] ?? '') && preg_match('/^[A-Z0-9]{3}$/D', $r['party_id'] ?? '') && in_array($r['role'] ?? null, ['CPO', 'EMSP'], true) && is_string($r['business_details']['name'] ?? null) && strlen($r['business_details']['name']) >= 1 && strlen($r['business_details']['name']) <= 100, 409, 'Configured local OCPI business identity is required.');
    }

    public function configurationHash(OcpiProvider $p): string
    {
        [$c, $local] = $this->configuration($p);

        // Preserve every existing outbound fingerprint across the additive enrollment upgrade.
        $identity = [$p->connection_key, rtrim($c['origin'], '/'), $c['versions_path'] ?? '/ocpi/versions', $c['party_country'], $c['party_id'], $c['party_role'], hash('sha256', (string) ($c['authorization_token'] ?? ''))];
        if (($c['registration_direction'] ?? 'outbound') === 'incoming') {
            $identity[] = ['incoming', hash('sha256', (string) ($c['inbound_authorization_token'] ?? ''))];
        }
        $identity[] = $local;

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public function localPayload(string $token): array
    {
        $local = config('ocpi.local_credentials');
        $this->validateLocal($local);
        $r = $local['roles'][0];

        return ['token' => $token, 'url' => $local['url'], 'roles' => [['role' => $r['role'], 'country_code' => $r['country_code'], 'party_id' => $r['party_id'], 'business_details' => ['name' => $r['business_details']['name']]]]];
    }

    public function validatePeer(OcpiProvider $p, array $body): array
    {
        [$c] = $this->configuration($p);
        abort_unless(is_string($body['token'] ?? null) && preg_match('/^[\x21-\x7e]{1,64}$/D', $body['token']) && is_string($body['url'] ?? null) && is_array($body['roles'] ?? null) && array_is_list($body['roles']) && count($body['roles']) === 1, 409, 'Peer credentials or identity are invalid.');
        $url = app(PartnerClient::class)->guardedUrl($p, $body['url']);
        $role = $body['roles'][0];
        abort_unless(is_array($role) && $url === rtrim($c['origin'], '/').($c['versions_path'] ?? '/ocpi/versions') && ($role['country_code'] ?? null) === $c['party_country'] && ($role['party_id'] ?? null) === $c['party_id'] && ($role['role'] ?? null) === $c['party_role'], 409, 'Peer credentials escaped the approved source identity.');

        return ['token' => $body['token']];
    }

    private function wire(OcpiProvider $p, string $url, string $encodedToken, string $method = 'GET', ?array $body = null): array
    {
        $current = OcpiProvider::findOrFail($p->id);
        abort_unless($current->authorized && $current->owner_id === $p->owner_id && $current->connection_key === $p->connection_key && $current->authorization_revision === $p->authorization_revision, 409, 'Partner authority changed before transport.');
        $url = app(PartnerClient::class)->guardedUrl($p, $url);
        try {
            $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Token '.$encodedToken, 'X-Request-ID' => (string) Str::uuid(), 'X-Correlation-ID' => (string) Str::uuid()])->send($method, $url, $body === null ? [] : ['json' => $body]);
            abort_unless($response->successful(), 503);
            $stream = $response->toPsrResponse()->getBody();
            $text = '';
            try {
                while (! $stream->eof() && strlen($text) <= 65536) {
                    $text .= $stream->read(min(8192, 65537 - strlen($text)));
                }
            } finally {
                $stream->close();
            }
            abort_unless(strlen($text) <= 65536, 503);
            $reply = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($reply) && ($reply['status_code'] ?? null) === 1000 && is_string($reply['timestamp'] ?? null) && strtotime($reply['timestamp']) !== false && ($method === 'DELETE' || array_key_exists('data', $reply)), 503);

            return $reply;
        } catch (Throwable) {
            throw new OcpiException('Partner credentials request failed; private transport details are withheld.');
        }
    }

    public function endpoint(OcpiProvider $p, string $token): string
    {
        [$c] = $this->configuration($p);
        $versions = $this->wire($p, $c['versions_path'] ?? '/ocpi/versions', $token)['data'];
        abort_unless(is_array($versions) && array_is_list($versions) && count($versions) <= 20, 409, 'Invalid peer versions.');
        $v = array_values(array_filter($versions, fn ($x) => is_array($x) && ($x['version'] ?? null) === '2.2.1'));
        abort_unless(count($v) === 1 && is_string($v[0]['url'] ?? null), 409, 'A unique OCPI2.2.1 advertisement is required.');
        $details = $this->wire($p, $v[0]['url'], $token)['data'];
        abort_unless(is_array($details) && ($details['version'] ?? null) === '2.2.1' && is_array($details['endpoints'] ?? null) && count($details['endpoints']) <= 30, 409);
        $endpoints = array_values(array_filter($details['endpoints'], fn ($e) => is_array($e) && ($e['identifier'] ?? null) === 'credentials' && ($e['role'] ?? null) === 'RECEIVER'));
        abort_unless(count($endpoints) === 1 && is_string($endpoints[0]['url'] ?? null), 409, 'Unique peer credentials receiver is required.');

        return app(PartnerClient::class)->guardedUrl($p, $endpoints[0]['url']);
    }

    public function exchange(OcpiProvider $p, int $actor, array $v): array
    {
        $hash = hash('sha256', json_encode([$v['action'], $v['reason']], JSON_THROW_ON_ERROR));
        $old = DB::table('ocpi_credential_operations')->where('provider_id', $p->id)->where('idempotency_key', $v['idempotency_key'])->first();
        if ($old) {
            abort_unless(hash_equals($old->payload_hash, $hash), 409, 'Operation identity is bound to its original payload.');

            return $this->operation($old);
        }
        [$c] = $this->configuration($p);
        $client = app(PartnerClient::class);
        abort_unless($p->authorized && ($v['action'] === 'unregister' || $p->authorization_revision < PHP_INT_MAX) && User::whereKey($p->owner_id)->where('role', 'vendor')->exists(), 409);
        $link = DB::table('ocpi_credential_links')->where('provider_id', $p->id)->first();
        abort_unless($v['action'] !== 'register' || ($c['registration_direction'] ?? 'outbound') === 'outbound', 409, 'This connection requires incoming partner enrollment.');
        $bootstrap = (string) ($c['authorization_token'] ?? '');
        $rawBootstrap = base64_decode($bootstrap, true);
        if ($v['action'] === 'register') {
            abort_unless(is_string($rawBootstrap) && preg_match('/^[\x21-\x7e]{1,64}$/D', $rawBootstrap) && base64_encode($rawBootstrap) === $bootstrap, 409, 'Managed bootstrap credentials must be OCPI Base64 encoded.');
        }
        if ($v['action'] === 'register') {
            abort_unless(! $link || in_array($link->state, ['revoked', 'unregistered'], true), 409, 'An unresolved or active credentials exchange already exists.');
            abort_unless(! $link || ! hash_equals($link->bootstrap_hash, hash('sha256', $bootstrap)), 409, 'A fresh operator-provisioned bootstrap credential is required.');
            $token = $bootstrap;
        } else {
            abort_unless($link && $link->state === 'active', 409, 'Current active managed credentials are required.');
            $token = $client->connection($p)['authorization_token'];
        }
        // Discovery is read-only; the atomic claim below fences any changes it observes.
        $endpoint = $this->endpoint($p, $token);
        $localToken = bin2hex(random_bytes(32));
        $payload = $v['action'] === 'unregister' ? null : $this->localPayload($localToken);
        $configuration = $this->configurationHash($p);
        $version = $v['action'] === 'unregister' && ($link->generation ?? 0) === 2147483647 ? 2147483647 : (int) ($link->generation ?? 0) + 1;
        abort_unless($version <= 2147483647, 409, 'Credential generation exhausted.');
        $opId = DB::transaction(function () use ($p, $v, $hash, $actor, $endpoint, $payload, $configuration, $version, $link, $bootstrap, $localToken) {
            $current = OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $this->unchanged($p, $current, $configuration, $v['action'] === 'unregister');
            abort_unless(User::whereKey($actor)->where('role', 'admin')->exists(), 403);
            $existing = DB::table('ocpi_credential_links')->where('provider_id', $p->id)->lockForUpdate()->first();
            abort_unless(($existing->generation ?? null) === ($link->generation ?? null) && ($existing->state ?? null) === ($link->state ?? null), 409, 'Credential state changed before dispatch.');
            abort_if(DB::table('ocpi_credential_operations')->where('provider_id', $p->id)->where('idempotency_key', $v['idempotency_key'])->exists(), 409);
            $capsule = json_encode(['endpoint' => $endpoint, 'method' => ['register' => 'POST', 'rotate' => 'PUT', 'unregister' => 'DELETE'][$v['action']], 'body' => $payload], JSON_THROW_ON_ERROR);
            $id = DB::table('ocpi_credential_operations')->insertGetId(['provider_id' => $p->id, 'actor_id' => $actor, 'generation' => $version, 'action' => $v['action'], 'idempotency_key' => $v['idempotency_key'], 'payload_hash' => $hash, 'reason' => Crypt::encryptString($v['reason']), 'source_payload_ciphertext' => Crypt::encryptString($capsule), 'source_payload_hash' => hash('sha256', $capsule), 'state' => 'dispatching', 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ocpi_credential_links')->updateOrInsert(['provider_id' => $p->id], ['owner_id' => $p->owner_id, 'generation' => $version, 'state' => 'dispatching', 'configuration_hash' => $configuration, 'bootstrap_hash' => $v['action'] === 'register' ? hash('sha256', $bootstrap) : $link->bootstrap_hash, 'local_token_digest' => $payload ? hash('sha256', $localToken) : null, 'local_token_ciphertext' => Crypt::encryptString($localToken), 'remote_token_ciphertext' => $link->remote_token_ciphertext ?? null, 'created_at' => $link->created_at ?? now(), 'updated_at' => now()]);

            return $id;
        }, 3);
        try {
            DB::transaction(function () use ($p, $actor, $configuration, $opId, $version, $token, $endpoint, $payload, $v, $localToken) {
                $current = OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                $this->unchanged($p, $current, $configuration, $v['action'] === 'unregister');
                abort_unless(User::whereKey($actor)->where('role', 'admin')->exists(), 403);
                $op = DB::table('ocpi_credential_operations')->where('id', $opId)->lockForUpdate()->firstOrFail();
                $link = DB::table('ocpi_credential_links')->where('provider_id', $p->id)->lockForUpdate()->firstOrFail();
                abort_unless($op->state === 'dispatching' && $link->state === 'dispatching' && $link->generation === $version, 409);
                // bounded one-shot transport under the provider authority lock;
                // no transaction retry can replay a device/partner mutation.
                $serialized = Crypt::decryptString($op->source_payload_ciphertext);
                abort_unless(hash_equals($op->source_payload_hash, hash('sha256', $serialized)), 409);
                $owned = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR);
                abort_unless($owned === ['endpoint' => $endpoint, 'method' => ['register' => 'POST', 'rotate' => 'PUT', 'unregister' => 'DELETE'][$v['action']], 'body' => $payload], 409);
                $reply = $this->wire($p, $owned['endpoint'], $token, $owned['method'], $owned['body']);
                $peer = $v['action'] === 'unregister' ? null : $this->validatePeer($p, is_array($reply['data'] ?? null) ? $reply['data'] : []);
                DB::table('ocpi_credential_links')->where('id', $link->id)->update(['state' => $peer ? 'active' : 'unregistered', 'local_token_digest' => $peer ? hash('sha256', $localToken) : null, 'local_token_ciphertext' => $peer ? Crypt::encryptString($localToken) : null, 'remote_token_ciphertext' => $peer ? Crypt::encryptString($peer['token']) : null, 'exchanged_at' => now(), 'updated_at' => now()]);
                $current->update(['version' => null, 'endpoints' => null, 'negotiated_at' => null, 'authorization_revision' => $current->authorization_revision < PHP_INT_MAX ? $current->authorization_revision + 1 : PHP_INT_MAX]);
                DB::table('ocpi_credential_operations')->where('id', $opId)->update(['state' => 'acknowledged', 'completed_at' => now(), 'updated_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $op->actor_id, 'action' => 'ocpi.credentials_'.$v['action'], 'subject' => (string) $p->id, 'created_at' => now()]);
            });
        } catch (Throwable) {
            DB::transaction(function () use ($opId, $p, $version) {
                OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_operations')->where('id', $opId)->where('state', 'dispatching')->update(['state' => 'outcome_unknown', 'updated_at' => now()]);
                DB::table('ocpi_credential_links')->where('provider_id', $p->id)->where('generation', $version)->where('state', 'dispatching')->update(['state' => 'outcome_unknown', 'local_token_digest' => null, 'updated_at' => now()]);
            });
        }

        return $this->operation(DB::table('ocpi_credential_operations')->where('id', $opId)->first());
    }

    private function unchanged(OcpiProvider $original, OcpiProvider $current, string $configuration, bool $unregister = false): void
    {
        abort_unless($current->authorized && $current->owner_id === $original->owner_id && $current->connection_key === $original->connection_key && $current->authorization_revision === $original->authorization_revision && ($unregister || $current->authorization_revision < PHP_INT_MAX) && User::whereKey($current->owner_id)->where('role', 'vendor')->exists() && hash_equals($configuration, $this->configurationHash($current)), 409, 'Partner authority changed; exchange was fenced.');
    }

    public function operation(object $row): array
    {
        return array_intersect_key((array) $row, array_flip(['id', 'generation', 'action', 'state', 'attempted_at', 'completed_at', 'created_at']));
    }
}
