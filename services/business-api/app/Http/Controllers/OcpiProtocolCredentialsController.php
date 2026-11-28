<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CpoCatalogContract;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\IncomingEnrollment;
use App\Services\Ocpi\OcpiMessageHeaders;
use App\Services\Ocpi\SandboxRoaming;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** OCPI protocol authority is exclusively the active opaque partner token, never browser cookies. */
class OcpiProtocolCredentialsController extends Controller
{
    public function authenticate(Request $r, CredentialExchange $exchange, string $resource): array
    {
        abort_unless($r->isSecure() || app()->environment(['local', 'testing']), 401);
        $header = $r->header('Authorization', '');
        abort_unless(is_string($header) && strlen($header) <= 100 && preg_match('/^Token ([A-Za-z0-9+\/=]+)$/Di', $header, $m), 401);
        $raw = base64_decode($m[1], true);
        abort_unless(is_string($raw) && preg_match('/^[\x21-\x7e]{1,64}$/D', $raw) && base64_encode($raw) === $m[1], 401);
        $link = DB::table('ocpi_credential_links')->where('local_token_digest', hash('sha256', $raw))->first();
        abort_unless($link, 401);
        // A registering peer may verify our versions URL with token B before
        // acknowledging token C. That provisional token grants only bounded
        // read-only discovery, never credentials reads/updates or any modules.
        $provisional = $link->state === 'dispatching' && $r->isMethod('GET') && in_array($resource, ['versions', 'details'], true)
            && DB::table('ocpi_credential_operations')->where('provider_id', $link->provider_id)->where('generation', $link->generation)->where('state', 'dispatching')->whereIn('action', ['register', 'rotate'])->where('attempted_at', '>=', now()->subSeconds(60))->exists();
        $enrolling = $link->state === 'awaiting_registration' && $link->bootstrap_expires_at && CarbonImmutable::parse($link->bootstrap_expires_at)->isFuture() && (($r->isMethod('GET') && in_array($resource, ['versions', 'details'], true)) || ($r->isMethod('POST') && $resource === 'credentials'));
        abort_unless($link->state === 'active' || $provisional || $enrolling, 401);
        $p = OcpiProvider::findOrFail($link->provider_id);
        abort_unless($p->authorized && $p->owner_id === $link->owner_id && User::whereKey($p->owner_id)->where('role', 'vendor')->exists() && ($resource !== 'catalog' || User::whereKey($p->owner_id)->where('account_status', 'active')->exists()) && hash_equals($link->configuration_hash, $exchange->configurationHash($p)), 401);

        return [$p, $link];
    }

    private function reply(mixed $data, int $code = 1000, int $http = 200)
    {
        $ids = OcpiMessageHeaders::response(request());

        return response()->json(['status_code' => $code, 'timestamp' => now()->toISOString(), 'data' => $data], $http)->header('Cache-Control', 'private, no-store')->header('X-Request-ID', $ids['X-Request-ID'])->header('X-Correlation-ID', $ids['X-Correlation-ID']);
    }

    public function handle(Request $r, CredentialExchange $exchange, string $resource = 'versions')
    {
        try {
            [$p, $link] = $this->authenticate($r, $exchange, $resource);
            $localToken = '';
            if ($link->state === 'active') {
                // The authenticated digest and encrypted local token must
                // describe the same generation. A mismatched ciphertext must
                // never disclose a different token to the previous peer.
                try {
                    $localToken = Crypt::decryptString($link->local_token_ciphertext);
                } catch (Throwable) {
                    abort(401);
                }
                abort_unless(preg_match('/^[\x21-\x7e]{1,64}$/D', $localToken)
                    && hash_equals($link->local_token_digest, hash('sha256', $localToken)), 401);
            }
            $local = $exchange->localPayload($localToken, $p);
            if ($r->isMethod('POST') && $resource === 'credentials') {
                if ($link->state !== 'awaiting_registration') {
                    return $this->reply(null, 2000, 405);
                }
                abort_unless(strlen($r->getContent()) <= 65536, 413);

                return $this->reply(app(IncomingEnrollment::class)->accept($p, $link, $r->json()->all()), 1000, 201);
            }
            $base = substr($local['url'], 0, -strlen('versions'));
            if ($r->isMethod('GET')) {
                $sandbox = [];
                if ($resource === 'details' && app(SandboxRoaming::class)->tokenSenderConfigured($p)) {
                    $sandbox[] = ['identifier' => 'tokens', 'role' => 'SENDER', 'url' => $base.'2.2.1/tokens'];
                }
                $senders = app(CpoCatalogContract::class)->approved($p)
                    ? array_map(fn ($module) => ['identifier' => $module, 'role' => 'SENDER', 'url' => $base.'2.2.1/cpo/'.$module], app(CpoCatalogContract::class)->modules($p))
                    : [];
                $data = match ($resource) {
                    'versions' => [['version' => '2.2.1', 'url' => $base.'2.2.1']],
                    'details' => ['version' => '2.2.1', 'endpoints' => array_merge([['identifier' => 'credentials', 'role' => 'RECEIVER', 'url' => $base.'2.2.1/credentials']], array_map(fn ($module) => ['identifier' => $module, 'role' => 'RECEIVER', 'url' => $base.'2.2.1/'.$module], $exchange->receiverModules($p)), $sandbox, $senders)],
                    'credentials' => $local,
                };

                return $this->reply($data);
            }
            abort_unless($resource === 'credentials' && in_array($r->method(), ['PUT', 'DELETE'], true) && strlen($r->getContent()) <= 65536, 405);
            $body = $r->isMethod('DELETE') ? null : $r->json()->all();
            $peer = $body === null ? null : $exchange->validatePeer($p, $body);
            if ($peer) {
                $exchange->endpoint($p, base64_encode($peer['token']), $link);
            }
            $data = DB::transaction(function () use ($p, $link, $exchange, $peer, $body) {
                $owner = User::whereKey($p->owner_id)->lockForUpdate()->first(['id', 'role', 'account_status']);
                abort_unless($owner?->role === 'vendor' && ($peer === null || $owner->account_status === 'active'), 401);
                $current = OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                $active = DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->authorized && $current->owner_id === $p->owner_id && $current->connection_key === $p->connection_key
                    && $current->authorization_revision === $p->authorization_revision && $active->owner_id === $owner->id
                    && $active->state === 'active' && $exchange->sameLink($link, $active)
                    && hash_equals($link->configuration_hash, $active->configuration_hash)
                    && hash_equals($active->configuration_hash, $exchange->configurationHash($current)), 401);
                if ($peer) {
                    abort_unless($active->generation < 2147483647 && $current->authorization_revision < PHP_INT_MAX, 409);
                }
                $generation = min(2147483647, $active->generation + 1);
                $token = bin2hex(random_bytes(32));
                DB::table('ocpi_credential_links')->where('id', $active->id)->update(['state' => $peer ? 'active' : 'revoked', 'generation' => $generation, 'local_token_digest' => $peer ? hash('sha256', $token) : null, 'local_token_ciphertext' => $peer ? Crypt::encryptString($token) : null, 'remote_token_ciphertext' => $peer ? Crypt::encryptString($peer['token']) : null, 'exchanged_at' => now(), 'updated_at' => now()]);
                $current->update(['authorized' => $peer ? true : false, 'version' => null, 'endpoints' => null, 'negotiated_at' => null, 'authorization_revision' => $current->authorization_revision < PHP_INT_MAX ? $current->authorization_revision + 1 : PHP_INT_MAX]);
                $capsule = json_encode($body, JSON_THROW_ON_ERROR);
                DB::table('ocpi_credential_operations')->insert(['provider_id' => $p->id, 'actor_id' => null, 'generation' => $generation, 'action' => $peer ? 'peer_rotate' : 'peer_revoke', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $capsule), 'reason' => Crypt::encryptString('Authenticated peer credentials lifecycle operation'), 'source_payload_ciphertext' => Crypt::encryptString($capsule), 'source_payload_hash' => hash('sha256', $capsule), 'state' => 'acknowledged', 'attempted_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

                return $peer ? $exchange->localPayload($token, $current) : null;
            });

            return $this->reply($data);
        } catch (Throwable $e) {
            $http = $e instanceof HttpExceptionInterface && $e->getStatusCode() === 401 ? 401 : 400;

            return $this->reply(null, $http === 401 ? 2004 : 2000, $http);
        }
    }
}
