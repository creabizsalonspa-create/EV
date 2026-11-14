<?php

namespace App\Services\Charging;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Per-session online credentials; never a shared-tenant unrestricted token. */
class RemoteSessionAuthorization
{
    public const CALLBACK = 'http://ev-business-api:8000/api/internal/remote-session-authorize';

    public function graph(string $query, array $variables): array
    {
        abort_unless(strlen((string) config('csms.graphql_secret')) >= 32, 503);
        try {
            $r = Http::timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => config('csms.graphql_secret')])->post(config('csms.graphql_url'), compact('query', 'variables'));
            $data = $r->json('data');
        } catch (Throwable) {
            abort(503, 'Authenticated source is unavailable.');
        }
        abort_unless($r->successful() && ! $r->json('errors') && is_array($data), 503, 'Authenticated source outcome requires inspection.');

        return $data;
    }

    public function enabled(): void
    {
        abort_unless(config('csms.remote_authorization_enabled') && config('csms.rider_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']), 503, 'Online remote-session authorizer is disabled.');
    }

    private function source(SessionLink $link, string $tag): ?array
    {
        $rows = $this->graph('query($tag:citext!){Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id idToken idTokenType status additionalInfo realTimeAuth realTimeAuthUrl realTimeAuthTimeout cacheExpiryDateTime concurrentTransaction isPrepaid}}', ['tag' => $tag])['Authorizations'];
        abort_unless(count($rows) <= 1, 409, 'Ambiguous remote source identity.');

        return $rows[0] ?? null;
    }

    private function owned(SessionLink $link, array $row, string $tag): void
    {
        $info = $row['additionalInfo'] ?? null;
        abort_unless(is_int($row['id'] ?? null) && $row['id'] > 0 && ($row['idToken'] ?? null) === $tag && ($row['idTokenType'] ?? null) === 'Central' && is_array($info) && count($info) === 1 && count($info[0]) === 2 && ($info[0]['type'] ?? null) === 'CREABIZ_REMOTE' && ($info[0]['additionalIdToken'] ?? null) === $link->remote_registration_nonce, 409, 'Source credential is not owned by this session.');
    }

    public function publish(ChargerCommand $command): void
    {
        $this->enabled();
        $link = SessionLink::where('start_command_id', $command->id)->firstOrFail();
        $tag = $command->payload['id_token'] ?? null;
        abort_unless(is_string($tag) && preg_match('/^EV-[A-F0-9]{16}$/D', $tag) && is_string($link->remote_token_digest) && hash_equals($link->remote_token_digest, hash('sha256', $tag)) && Str::isUuid($link->remote_registration_nonce), 409, 'Canonical remote credential is unavailable.');
        $nonce = (string) Str::uuid();
        try {
            $r = Http::timeout(8)->withoutRedirecting()->get(rtrim(config('csms.rider_source_api_url'), '/').'/creabiz/rider-auth/capabilities', ['nonce' => $nonce]);
            $proof = $r->json('proof');
        } catch (Throwable) {
            abort(503, 'Source online authorizer is unavailable.');
        }
        $secret = (string) config('csms.rider_client_secret');
        abort_unless(strlen($secret) >= 32 && $r->successful() && $r->json('remote_session_authorization') === true && $r->json('ready') === true && $r->json('tenant_id') === 1 && $r->json('offline_fallback') === false && $r->json('cache_seconds') === 0 && is_string($proof) && hash_equals(hash_hmac('sha256', "creabiz-rider-v1\n".$nonce, $secret), $proof), 503, 'Source online authorizer is not verified.');
        $reservation = DB::transaction(function () use ($link, $command) {
            $session = ChargingSession::findOrFail($link->charging_session_id);
            $reservation = Reservation::findOrFail($session->reservation_id);
            $station = Station::whereKey($reservation->station_id)->lockForUpdate()->firstOrFail();
            app(StationGate::class)->requireReady($station, $reservation->connector_mapping_id);
            app(OperatingCalendar::class)->requireStart($station);
            $current = ChargerCommand::whereKey($command->id)->lockForUpdate()->firstOrFail();
            $binding = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
            abort_unless($binding && $binding->station_id === $station->id && $binding->identifier === $link->identifier && $binding->protocol === $link->protocol && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $link->identifier) && $current->actor_id === $reservation->user_id && User::whereKey($reservation->user_id)->where('role', 'customer')->exists() && app(ChargingActorConsent::class)->matches(User::find($reservation->user_id), $current->consent_auth_epoch) && $current->status === 'pending' && $current->failure_code === null && $reservation->status === 'confirmed' && $session->status === 'pending_start' && $link->started_at === null && $link->closed_without_dispatch_at === null && $reservation->connector_mapping_id, 409, 'Canonical remote start is unavailable.');
            $start = CarbonImmutable::parse($reservation->starts_at);
            abort_unless(now()->gte($start) && now()->lt($start->addMinutes(30)), 409, 'Outside the booking window.');

            return $reservation;
        });
        $expiry = CarbonImmutable::parse($reservation->starts_at)->addMinutes(30);
        $row = $this->source($link, $tag);
        if (! $row) {
            $object = ['tenantId' => 1, 'idToken' => $tag, 'idTokenType' => 'Central', 'status' => 'Blocked', 'additionalInfo' => [['additionalIdToken' => $link->remote_registration_nonce, 'type' => 'CREABIZ_REMOTE']], 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => self::CALLBACK, 'realTimeAuthTimeout' => 0, 'cacheExpiryDateTime' => $expiry->toISOString(), 'concurrentTransaction' => false, 'isPrepaid' => false, 'createdAt' => now()->toISOString(), 'updatedAt' => now()->toISOString()];
            $this->graph('mutation($object:Authorizations_insert_input!){insert_Authorizations_one(object:$object){id}}', compact('object'));
            $row = $this->source($link, $tag);
        }
        abort_unless($row, 503);
        $this->owned($link, $row, $tag);
        $link->refresh();
        abort_unless($link->remote_authorization_id === null || (int) $link->remote_authorization_id === $row['id'], 409, 'Remote source registration changed.');
        SessionLink::whereKey($link->id)->whereNull('remote_authorization_id')->update(['remote_authorization_id' => $row['id']]);
        $set = ['status' => 'Accepted', 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => self::CALLBACK, 'realTimeAuthTimeout' => 0, 'realTimeAuthLastAttempt' => null, 'cacheExpiryDateTime' => $expiry->toISOString(), 'concurrentTransaction' => false, 'isPrepaid' => false, 'updatedAt' => now()->toISOString()];
        $changed = $this->graph('mutation($id:Int!,$marker:jsonb!,$set:Authorizations_set_input!){update_Authorizations(where:{id:{_eq:$id},tenantId:{_eq:1},additionalInfo:{_eq:$marker}},_set:$set){affected_rows}}', ['id' => $row['id'], 'marker' => $row['additionalInfo'], 'set' => $set]);
        abort_unless(($changed['update_Authorizations']['affected_rows'] ?? null) === 1, 409, 'Source ownership changed.');
        abort_unless($this->verified($link->fresh(), $tag), 503, 'Remote publication requires inspection.');
    }

    public function verified(SessionLink $link, string $tag): bool
    {
        $row = $this->source($link, $tag);
        if (! $row) {
            return false;
        }$this->owned($link, $row, $tag);

        return (int) $link->remote_authorization_id === $row['id'] && $row['status'] === 'Accepted' && $row['realTimeAuth'] === 'Never' && $row['realTimeAuthUrl'] === self::CALLBACK && $row['realTimeAuthTimeout'] === 0 && $row['concurrentTransaction'] === false && $row['isPrepaid'] === false && CarbonImmutable::parse($row['cacheExpiryDateTime'])->isFuture();
    }
}
