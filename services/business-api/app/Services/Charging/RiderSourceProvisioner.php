<?php

namespace App\Services\Charging;

use App\Models\Charging\RiderAccessToken;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Source identity is immutable; publication uses observed JSON compare-and-set. */
class RiderSourceProvisioner
{
    private function graph(string $query, array $variables): array
    {
        abort_unless(strlen((string) config('csms.graphql_secret')) >= 32, 503, 'Authenticated source is unavailable.');
        try {
            $r = Http::timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), compact('query', 'variables'));
            $data = $r->json('data');
        } catch (Throwable) {
            abort(503, 'Source outcome requires inspection.');
        }
        abort_unless($r->successful() && ! $r->json('errors') && is_array($data), 503, 'Source outcome requires inspection.');

        return $data;
    }

    private function lookup(string $raw): ?array
    {
        $rows = $this->graph('query($tag:citext!){Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id idToken idTokenType status realTimeAuth realTimeAuthUrl realTimeAuthTimeout additionalInfo cacheExpiryDateTime concurrentTransaction isPrepaid}}', ['tag' => $raw])['Authorizations'] ?? null;
        abort_unless(is_array($rows) && count($rows) <= 1, 409, 'Ambiguous source identity requires reconciliation.');

        return $rows[0] ?? null;
    }

    private function owned(array $row, RiderAccessToken $token, string $raw): void
    {
        $marker = ['additionalIdToken' => $token->source_registration_nonce, 'type' => 'CREABIZ_RIDER'];
        $info = $row['additionalInfo'] ?? null;
        abort_unless(is_int($row['id'] ?? null) && $row['id'] > 0 && strtoupper($row['idToken'] ?? '') === $raw && ($row['idTokenType'] ?? null) === $token->token_type && is_array($info) && count($info) >= 1 && count($info) <= 2 && $this->marker($info[0] ?? null, $marker), 409, 'Source authorization is not owned by this registry.');
        if (count($info) === 2) {
            abort_unless(($info[1]['type'] ?? null) === 'CREABIZ_LEASE' && is_string($info[1]['additionalIdToken'] ?? null) && Str::isUuid($info[1]['additionalIdToken']), 409, 'Source publication marker changed.');
        }
    }

    private function marker(mixed $value, array $expected): bool
    {
        return is_array($value) && count($value) === 2 && is_string($value['additionalIdToken'] ?? null) && ($value['type'] ?? null) === $expected['type'] && hash_equals($expected['additionalIdToken'], $value['additionalIdToken']);
    }

    public function publish(User $actor, VendorCompany $company, int $tokenId): array
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
        $lease = (string) Str::uuid();
        $token = DB::transaction(function () use ($actor, $company, $tokenId, $lease) {
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
            $source = $this->lookup($raw);
            if ($source === null) {
                $object = ['tenantId' => 1, 'idToken' => $raw, 'idTokenType' => $token->token_type, 'status' => 'Blocked', 'additionalInfo' => [$marker], 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => $callback, 'realTimeAuthTimeout' => 0, 'concurrentTransaction' => false, 'isPrepaid' => false, 'cacheExpiryDateTime' => $token->expires_at->toISOString(), 'createdAt' => now()->toISOString(), 'updatedAt' => now()->toISOString()];
                $this->graph('mutation($object:Authorizations_insert_input!){insert_Authorizations_one(object:$object){id}}', compact('object'));
                $source = $this->lookup($raw);
            }
            abort_unless($source !== null, 503, 'Source creation is not yet observable.');
            $this->owned($source, $token, $raw);
            // A stale/timed-out writer cannot overwrite a newer publication: the
            // exact observed JSON marker must still match before source mutation.
            $next = [$marker, ['additionalIdToken' => $lease, 'type' => 'CREABIZ_LEASE']];
            $changes = ['status' => 'Accepted', 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => $callback, 'realTimeAuthTimeout' => 0, 'realTimeAuthLastAttempt' => null, 'additionalInfo' => $next, 'cacheExpiryDateTime' => $token->expires_at->toISOString(), 'concurrentTransaction' => false, 'isPrepaid' => false, 'updatedAt' => now()->toISOString()];
            $result = $this->graph('mutation($id:Int!,$old:jsonb!,$set:Authorizations_set_input!){update_Authorizations(where:{id:{_eq:$id},tenantId:{_eq:1},additionalInfo:{_eq:$old}},_set:$set){affected_rows}}', ['id' => $source['id'], 'old' => $source['additionalInfo'], 'set' => $changes]);
            abort_unless(($result['update_Authorizations']['affected_rows'] ?? null) === 1, 409, 'Source identity changed during publication.');
            $verified = $this->lookup($raw);
            abort_unless($verified !== null, 503);
            $this->owned($verified, $token, $raw);
            abort_unless(count($verified['additionalInfo']) === 2 && $this->marker($verified['additionalInfo'][1], $next[1]) && $verified['status'] === 'Accepted' && $verified['realTimeAuth'] === 'Never' && $verified['realTimeAuthUrl'] === $callback && $verified['realTimeAuthTimeout'] !== null && is_numeric($verified['realTimeAuthTimeout']) && (float) $verified['realTimeAuthTimeout'] === 0.0 && $verified['concurrentTransaction'] === false && $verified['isPrepaid'] === false && CarbonImmutable::parse($verified['cacheExpiryDateTime'])->equalTo($token->expires_at), 409, 'Source policy differs from the requested safe policy.');

            return DB::transaction(function () use ($actor, $company, $token, $lease, $verified) {
                $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->role === 'admin' || $actor->id === $company->owner_id, 404);
                $current = RiderAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->provision_lease === $lease && $current->revoked_at === null && $current->expires_at->isFuture() && DB::table('rider_charging_grants')->where('token_id', $current->id)->where('company_id', $company->id)->where('state', 'approved')->whereNull('revoked_at')->where('expires_at', '>', now())->exists(), 409, 'Approval changed during source publication.');
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
