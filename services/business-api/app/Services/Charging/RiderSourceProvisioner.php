<?php

namespace App\Services\Charging;

use App\Models\Charging\RiderAccessToken;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Source identity is immutable; publication uses observed JSON compare-and-set. */
class RiderSourceProvisioner
{
    private function sourceEndpoint(?string $expected = null): string
    {
        abort_unless(config('csms.rider_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']), 503, 'Local rider source adapter is not configured.');
        $url = app(CsmsGraphqlTransport::class)->requireApproved();
        abort_unless($expected === null || $url === $expected, 409, 'Rider source changed during inspection.');

        return $url;
    }

    private function graph(string $query, array $variables, ?string $sourceUrl = null): array
    {
        abort_unless(strlen((string) config('csms.graphql_secret')) >= 32, 503, 'Authenticated source is unavailable.');
        $url = $sourceUrl === null ? app(CsmsGraphqlTransport::class)->requireApproved() : $this->sourceEndpoint($sourceUrl);
        try {
            $r = Http::timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post($url, compact('query', 'variables'));
            $data = $r->json('data');
        } catch (Throwable) {
            abort(503, 'Source outcome requires inspection.');
        }
        abort_unless($r->successful() && ! $r->json('errors') && is_array($data), 503, 'Source outcome requires inspection.');

        if ($sourceUrl !== null) {
            $this->sourceEndpoint($sourceUrl);
        }

        return $data;
    }

    private function lookup(string $raw, string $sourceUrl): ?array
    {
        $rows = $this->graph('query($tag:citext!){Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id idToken idTokenType status realTimeAuth realTimeAuthUrl realTimeAuthTimeout additionalInfo cacheExpiryDateTime concurrentTransaction isPrepaid}}', ['tag' => $raw], $sourceUrl)['Authorizations'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 1, 409, 'Malformed or ambiguous source identity requires reconciliation.');
        if ($rows !== []) {
            $row = $rows[0];
            abort_unless(is_array($row) && is_int($row['id'] ?? null) && $row['id'] > 0 &&
                is_string($row['idToken'] ?? null) && is_string($row['idTokenType'] ?? null),
                409, 'Malformed source identity requires reconciliation.');
        }

        return $rows[0] ?? null;
    }

    private function owned(array $row, RiderAccessToken $token, string $raw): void
    {
        $marker = ['additionalIdToken' => $token->source_registration_nonce, 'type' => 'CREABIZ_RIDER'];
        $info = $row['additionalInfo'] ?? null;
        abort_unless(is_int($row['id'] ?? null) && $row['id'] > 0 && strtoupper($row['idToken'] ?? '') === $raw && ($row['idTokenType'] ?? null) === $token->token_type && is_array($info) && array_is_list($info) && count($info) >= 1 && count($info) <= 2 && $this->marker($info[0] ?? null, $marker), 409, 'Source authorization is not owned by this registry.');
        if (count($info) === 2) {
            abort_unless(($info[1]['type'] ?? null) === 'CREABIZ_LEASE' && is_string($info[1]['additionalIdToken'] ?? null) && Str::isUuid($info[1]['additionalIdToken']), 409, 'Source publication marker changed.');
        }
    }

    private function expiryMatches(mixed $value, CarbonImmutable $expected): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value, $parts)) {
            return false;
        }
        $normalized = $parts[1].'.'.str_pad($parts[2] ?? '', 6, '0').($parts[3] === 'Z' ? '+00:00' : $parts[3]);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.uP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && CarbonImmutable::instance($parsed)->equalTo($expected) && $expected->isFuture();
    }

    private function marker(mixed $value, array $expected): bool
    {
        return is_array($value) && count($value) === 2 && is_string($value['additionalIdToken'] ?? null) && ($value['type'] ?? null) === $expected['type'] && hash_equals($expected['additionalIdToken'], $value['additionalIdToken']);
    }

    /** Short local authorization checkpoint; no source HTTP call holds these locks. */
    private function currentPublication(User $actor, VendorCompany $company, ?RiderAccessToken $token = null, ?string $lease = null): void
    {
        DB::transaction(function () use ($actor, $company, $token, $lease) {
            app(RiderActor::class)->check($actor);
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($actor->role, ['vendor', 'admin'], true) && ($actor->role === 'admin' || $actor->id === $company->owner_id), 404);
            app(VendorAccess::class)->operational($company);
            if ($token === null) {
                return;
            }
            $current = RiderAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->provision_lease === $lease && $current->provision_lease_until?->isFuture()
                && $current->revoked_at === null && $current->expires_at->isFuture() && $current->expires_at->equalTo($token->expires_at)
                && DB::table('rider_charging_grants')->where('token_id', $current->id)->where('company_id', $company->id)->where('state', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 409, 'Approval changed during source publication.');
        }, 3);
    }

    private function verifiedAdapter(): string
    {
        abort_unless(config('csms.rider_enabled') && config('csms.session_bridge_enabled') && ! app()->environment('production') && strlen((string) config('csms.rider_client_secret')) >= 32, 503, 'Local rider source adapter is not configured.');
        $callback = (string) config('csms.rider_callback_url');
        abort_unless($callback === 'http://ev-business-api:8000/api/internal/rider-authorize', 503, 'Use the fixed local business callback for this simulator adapter.');
        $nonce = (string) Str::uuid();
        try {
            $cap = Http::timeout(10)->withoutRedirecting()->get(rtrim(config('csms.rider_source_api_url'), '/').'/creabiz/rider-auth/capabilities', ['nonce' => $nonce]);
            $proof = $cap->json('proof');
        } catch (Throwable) {
            abort(503, 'Source rider authorizer is not verified.');
        }
        abort_unless($cap->successful() && $cap->json('ready') === true && $cap->json('adapter_version') === 'creabiz-rider-v1' && $cap->json('tenant_id') === 1 && $cap->json('offline_fallback') === false && $cap->json('cache_seconds') === 0 && is_string($proof) && hash_equals(hash_hmac('sha256', "creabiz-rider-v1\n".$nonce, config('csms.rider_client_secret')), $proof), 503, 'Source rider authorizer is not verified.');

        return $callback;
    }

    /** Called only in a short transaction, with User -> company -> token ordering. */
    private function inactiveToken(User $actor, VendorCompany $company, int $tokenId, ?string $lease = null): RiderAccessToken
    {
        app(RiderActor::class)->check($actor);
        $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
        abort_unless(in_array($actor->role, ['vendor', 'admin'], true) && ($actor->role === 'admin' || $actor->id === $company->owner_id), 404);
        app(VendorAccess::class)->operational($company);
        $token = RiderAccessToken::whereKey($tokenId)->lockForUpdate()->firstOrFail();
        abort_unless(DB::table('rider_charging_grants')->where('token_id', $token->id)->where('company_id', $company->id)->exists(), 404);
        // A single-company withdrawal must never disable another company's active card.
        abort_unless($token->revoked_at !== null || $token->expires_at->lte(now()), 409, 'Only globally revoked or expired tokens can retire their source registration.');
        if ($lease !== null) {
            abort_unless($token->provision_lease === $lease && $token->provision_lease_until?->isFuture(), 409, 'Source retirement lease changed or expired.');
        }

        return $token;
    }

    public function retire(User $actor, VendorCompany $company, int $tokenId): array
    {
        abort_unless(config('csms.rider_enabled') && config('csms.session_bridge_enabled') && ! app()->environment('production'), 503, 'Local rider source adapter is not configured.');
        DB::transaction(fn () => $this->inactiveToken($actor, $company, $tokenId), 3);
        $sourceUrl = $this->sourceEndpoint();
        $this->verifiedAdapter();
        $this->sourceEndpoint($sourceUrl);
        $lease = (string) Str::uuid();
        $token = DB::transaction(function () use ($actor, $company, $tokenId, $lease) {
            $token = $this->inactiveToken($actor, $company, $tokenId);
            abort_if($token->provision_lease_until?->isFuture(), 409, 'Source inspection is already in progress.');
            $token->update(['provision_lease' => $lease, 'provision_lease_until' => now()->addSeconds(60), 'provisioning_state' => 'retiring', 'provisioned_at' => null]);
            app(RiderTokenRegistry::class)->event($token->id, null, $actor->id, 'source.retirement_requested');

            return $token;
        }, 3);
        try {
            $raw = Crypt::decryptString($token->token_ciphertext);
            $source = $this->lookup($raw, $sourceUrl);
            if ($source !== null) {
                $this->owned($source, $token, $raw);
                if (($source['status'] ?? null) !== 'Blocked') {
                    DB::transaction(fn () => $this->inactiveToken($actor, $company, $tokenId, $lease), 3);
                    $marker = [['additionalIdToken' => $token->source_registration_nonce, 'type' => 'CREABIZ_RIDER'], ['additionalIdToken' => $lease, 'type' => 'CREABIZ_LEASE']];
                    $set = ['status' => 'Blocked', 'additionalInfo' => $marker, 'updatedAt' => now()->toISOString()];
                    $sourceId = $source['id'];
                    $result = $this->graph('mutation($id:Int!,$old:jsonb!,$set:Authorizations_set_input!){update_Authorizations(where:{id:{_eq:$id},tenantId:{_eq:1},additionalInfo:{_eq:$old}},_set:$set){affected_rows}}', ['id' => $source['id'], 'old' => $source['additionalInfo'], 'set' => $set], $sourceUrl);
                    abort_unless(($result['update_Authorizations']['affected_rows'] ?? null) === 1, 409, 'Source identity changed during retirement.');
                    $source = $this->lookup($raw, $sourceUrl);
                    abort_unless($source !== null, 409, 'Source retirement is not yet observable.');
                    $this->owned($source, $token, $raw);
                    abort_unless($source['id'] === $sourceId, 409, 'Source retirement identity changed.');
                    abort_unless(($source['status'] ?? null) === 'Blocked' && count($source['additionalInfo']) === 2 && $this->marker($source['additionalInfo'][1], $marker[1]), 409, 'Source retirement requires inspection.');
                }
            }

            return DB::transaction(function () use ($actor, $company, $tokenId, $lease, $source, $sourceUrl) {
                $this->sourceEndpoint($sourceUrl);
                $current = $this->inactiveToken($actor, $company, $tokenId, $lease);
                $current->update(['provisioning_state' => 'retired', 'provisioned_at' => null, 'provision_lease' => null, 'provision_lease_until' => null]);
                app(RiderTokenRegistry::class)->event($current->id, null, $actor->id, $source === null ? 'source.retired_absent' : 'source.retired');

                return ['source_retirement_observed' => true, 'observed_at' => now()->toISOString(), 'source_record_absent' => $source === null, 'active_sessions_stopped' => false, 'hardware_verified' => false, 'evidence' => 'SIMULATED_SOURCE_SNAPSHOT'];
            }, 3);
        } catch (Throwable $error) {
            DB::transaction(function () use ($token, $lease, $actor) {
                $changed = RiderAccessToken::whereKey($token->id)->where('provision_lease', $lease)->update(['provisioning_state' => 'requires_inspection', 'provisioned_at' => null]);
                if ($changed) {
                    app(RiderTokenRegistry::class)->event($token->id, null, $actor->id, 'source.retirement_requires_inspection');
                }
            }, 3);
            throw $error;
        }
    }

    public function publish(User $actor, VendorCompany $company, int $tokenId): array
    {
        abort_unless(config('csms.rider_enabled') && config('csms.session_bridge_enabled') && ! app()->environment('production') && strlen((string) config('csms.rider_client_secret')) >= 32, 503, 'Local rider source adapter is not configured.');
        $this->currentPublication($actor, $company);
        $sourceUrl = $this->sourceEndpoint();
        $callback = $this->verifiedAdapter();
        $this->sourceEndpoint($sourceUrl);
        $lease = (string) Str::uuid();
        $token = DB::transaction(function () use ($actor, $company, $tokenId, $lease) {
            app(RiderActor::class)->check($actor);
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin' || $actor->id === $company->owner_id, 404);
            app(VendorAccess::class)->operational($company);
            $token = RiderAccessToken::whereKey($tokenId)->lockForUpdate()->firstOrFail();
            abort_unless($token->revoked_at === null && $token->expires_at->isFuture(), 409, 'Token is expired or revoked.');
            abort_unless(DB::table('rider_charging_grants')->where('token_id', $token->id)->where('company_id', $company->id)->where('state', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 404);
            abort_if($token->provision_lease_until?->isFuture(), 409, 'Source inspection is already in progress.');
            $token->update(['provision_lease' => $lease, 'provision_lease_until' => now()->addSeconds(60), 'provisioning_state' => 'inspecting', 'provisioned_at' => null]);

            return $token;
        }, 3);
        $raw = Crypt::decryptString($token->token_ciphertext);
        $marker = ['additionalIdToken' => $token->source_registration_nonce, 'type' => 'CREABIZ_RIDER'];
        try {
            $this->currentPublication($actor, $company, $token, $lease);
            $source = $this->lookup($raw, $sourceUrl);
            if ($source === null) {
                $object = ['tenantId' => 1, 'idToken' => $raw, 'idTokenType' => $token->token_type, 'status' => 'Blocked', 'additionalInfo' => [$marker], 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => $callback, 'realTimeAuthTimeout' => 0, 'concurrentTransaction' => false, 'isPrepaid' => false, 'cacheExpiryDateTime' => $token->expires_at->toISOString(), 'createdAt' => now()->toISOString(), 'updatedAt' => now()->toISOString()];
                $this->currentPublication($actor, $company, $token, $lease);
                $created = $this->graph('mutation($object:Authorizations_insert_input!){insert_Authorizations_one(object:$object){id}}', compact('object'), $sourceUrl)['insert_Authorizations_one']['id'] ?? null;
                abort_unless(is_int($created) && $created > 0, 409, 'Malformed source creation identity.');
                $source = $this->lookup($raw, $sourceUrl);
                abort_unless($source !== null && $source['id'] === $created, 409, 'Source creation identity changed.');
            }
            abort_unless($source !== null, 503, 'Source creation is not yet observable.');
            $this->owned($source, $token, $raw);
            // A stale/timed-out writer cannot overwrite a newer publication: the
            // exact observed JSON marker must still match before source mutation.
            $next = [$marker, ['additionalIdToken' => $lease, 'type' => 'CREABIZ_LEASE']];
            $changes = ['status' => 'Accepted', 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => $callback, 'realTimeAuthTimeout' => 0, 'realTimeAuthLastAttempt' => null, 'additionalInfo' => $next, 'cacheExpiryDateTime' => $token->expires_at->toISOString(), 'concurrentTransaction' => false, 'isPrepaid' => false, 'updatedAt' => now()->toISOString()];
            $this->currentPublication($actor, $company, $token, $lease);
            $result = $this->graph('mutation($id:Int!,$old:jsonb!,$set:Authorizations_set_input!){update_Authorizations(where:{id:{_eq:$id},tenantId:{_eq:1},additionalInfo:{_eq:$old}},_set:$set){affected_rows}}', ['id' => $source['id'], 'old' => $source['additionalInfo'], 'set' => $changes], $sourceUrl);
            abort_unless(($result['update_Authorizations']['affected_rows'] ?? null) === 1, 409, 'Source identity changed during publication.');
            $verified = $this->lookup($raw, $sourceUrl);
            abort_unless($verified !== null, 503);
            $this->owned($verified, $token, $raw);
            abort_unless($verified['id'] === $source['id'], 409, 'Source publication identity changed.');
            abort_unless(count($verified['additionalInfo']) === 2 && $this->marker($verified['additionalInfo'][1], $next[1]) && $verified['status'] === 'Accepted' && $verified['realTimeAuth'] === 'Never' && $verified['realTimeAuthUrl'] === $callback && $verified['realTimeAuthTimeout'] !== null && is_numeric($verified['realTimeAuthTimeout']) && (float) $verified['realTimeAuthTimeout'] === 0.0 && $verified['concurrentTransaction'] === false && $verified['isPrepaid'] === false && $this->expiryMatches($verified['cacheExpiryDateTime'] ?? null, $token->expires_at), 409, 'Source policy differs from the requested safe policy.');

            return DB::transaction(function () use ($actor, $company, $token, $lease, $verified, $sourceUrl) {
                $this->sourceEndpoint($sourceUrl);
                app(RiderActor::class)->check($actor);
                $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                app(VendorAccess::class)->operational($company);
                abort_unless($actor->role === 'admin' || $actor->id === $company->owner_id, 404);
                $current = RiderAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->provision_lease === $lease && $current->provision_lease_until?->isFuture() && $current->revoked_at === null && $current->expires_at->isFuture() && $current->expires_at->equalTo($token->expires_at) && DB::table('rider_charging_grants')->where('token_id', $current->id)->where('company_id', $company->id)->where('state', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 409, 'Approval changed during source publication.');
                $current->update(['source_authorization_id' => $verified['id'], 'provisioned_at' => now(), 'provisioning_state' => 'active', 'provision_lease' => null, 'provision_lease_until' => null]);
                app(RiderTokenRegistry::class)->event($current->id, null, $actor->id, 'source.provisioned');

                return ['source_provisioned' => true, 'hardware_verified' => false, 'evidence' => 'SIMULATED_SOURCE'];
            }, 3);
        } catch (Throwable $e) {
            RiderAccessToken::whereKey($token->id)->where('provision_lease', $lease)->update(['provisioning_state' => 'requires_inspection']);
            throw $e;
        }
    }
}
