<?php

namespace App\Services\Charging;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Per-session online credentials; never a shared-tenant unrestricted token. */
class RemoteSessionAuthorization
{
    public const CALLBACK = 'http://ev-business-api:8000/api/internal/remote-session-authorize';

    private function sourceAuthority(): array
    {
        $this->enabled();

        return [app(CsmsGraphqlTransport::class)->requireApproved(), (string) config('csms.graphql_secret'),
            (string) config('csms.rider_source_api_url'), (string) config('csms.rider_client_secret')];
    }

    private function requireAuthority(array $expected): void
    {
        abort_unless($this->sourceAuthority() === $expected, 503, 'Remote source authority changed during inspection.');
    }

    public function graph(string $query, array $variables, ?array $authority = null): array
    {
        $url = app(CsmsGraphqlTransport::class)->requireApproved();
        $key = (string) config('csms.graphql_secret');
        abort_unless(strlen($key) >= 32, 503);
        if ($authority !== null) {
            $this->requireAuthority($authority);
        }
        try {
            $r = Http::timeout(10)->withoutRedirecting()->withHeaders(['x-hasura-admin-secret' => $key])->post($url, compact('query', 'variables'));
            $data = $r->json('data');
        } catch (Throwable) {
            abort(503, 'Authenticated source is unavailable.');
        }
        if ($authority !== null) {
            $this->requireAuthority($authority);
        }
        abort_unless($r->successful() && ! $r->json('errors') && is_array($data), 503, 'Authenticated source outcome requires inspection.');

        return $data;
    }

    public function enabled(): void
    {
        abort_unless(config('csms.remote_authorization_enabled') && config('csms.rider_enabled') && config('csms.session_bridge_enabled') && app()->environment(['local', 'testing']), 503, 'Online remote-session authorizer is disabled.');
    }

    private function source(SessionLink $link, string $tag, array $authority): ?array
    {
        $rows = $this->graph('query($tag:citext!){Authorizations(where:{tenantId:{_eq:1},idToken:{_eq:$tag}},limit:2){id idToken idTokenType status additionalInfo realTimeAuth realTimeAuthUrl realTimeAuthTimeout cacheExpiryDateTime concurrentTransaction isPrepaid}}', ['tag' => $tag], $authority)['Authorizations'] ?? null;
        // GraphQL shape is untrusted even when the HTTP request succeeds.
        // An object-shaped one-row response must not masquerade as "absent"
        // and trigger creation of a second authorization in publish().
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 1, 409, 'Malformed or ambiguous remote source identity.');
        if ($rows !== []) {
            abort_unless(is_array($rows[0]) && is_int($rows[0]['id'] ?? null)
                && $rows[0]['id'] > 0 && is_string($rows[0]['idToken'] ?? null)
                && is_string($rows[0]['idTokenType'] ?? null),
                409, 'Malformed remote source identity.');
        }

        return $rows[0] ?? null;
    }

    private function owned(SessionLink $link, array $row, string $tag): void
    {
        $info = $row['additionalInfo'] ?? null;
        abort_unless(is_int($row['id'] ?? null) && $row['id'] > 0 && ($row['idToken'] ?? null) === $tag &&
            ($row['idTokenType'] ?? null) === 'Central' && is_array($info) && array_is_list($info) &&
            count($info) === 1 && is_array($info[0]) && count($info[0]) === 2 &&
            ($info[0]['type'] ?? null) === 'CREABIZ_REMOTE' &&
            is_string($info[0]['additionalIdToken'] ?? null) &&
            hash_equals((string) $link->remote_registration_nonce, $info[0]['additionalIdToken']),
            409, 'Source credential is not owned by this session.');
    }

    public function publish(ChargerCommand $command): void
    {
        $authority = $this->sourceAuthority();
        $link = SessionLink::where('start_command_id', $command->id)->firstOrFail();
        $tag = $command->payload['id_token'] ?? null;
        abort_unless(is_string($tag) && preg_match('/^EV-[A-F0-9]{16}$/D', $tag) && is_string($link->remote_token_digest) && hash_equals($link->remote_token_digest, hash('sha256', $tag)) && Str::isUuid($link->remote_registration_nonce), 409, 'Canonical remote credential is unavailable.');
        $nonce = (string) Str::uuid();
        try {
            $r = Http::timeout(8)->withoutRedirecting()->get(rtrim($authority[2], '/').'/creabiz/rider-auth/capabilities', ['nonce' => $nonce]);
            $proof = $r->json('proof');
        } catch (Throwable) {
            abort(503, 'Source online authorizer is unavailable.');
        }
        $this->requireAuthority($authority);
        $secret = $authority[3];
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
        $row = $this->source($link, $tag, $authority);
        if (! $row) {
            $object = ['tenantId' => 1, 'idToken' => $tag, 'idTokenType' => 'Central', 'status' => 'Blocked', 'additionalInfo' => [['additionalIdToken' => $link->remote_registration_nonce, 'type' => 'CREABIZ_REMOTE']], 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => self::CALLBACK, 'realTimeAuthTimeout' => 0, 'cacheExpiryDateTime' => $expiry->toISOString(), 'concurrentTransaction' => false, 'isPrepaid' => false, 'createdAt' => now()->toISOString(), 'updatedAt' => now()->toISOString()];
            $created = $this->graph('mutation($object:Authorizations_insert_input!){insert_Authorizations_one(object:$object){id}}', compact('object'), $authority)['insert_Authorizations_one']['id'] ?? null;
            abort_unless(is_int($created) && $created > 0, 409, 'Malformed remote source creation identity.');
            $row = $this->source($link, $tag, $authority);
            abort_unless($row !== null && $row['id'] === $created, 409, 'Remote source creation identity changed.');
        }
        abort_unless($row, 503);
        $this->owned($link, $row, $tag);
        $link->refresh();
        abort_unless($link->remote_authorization_id === null || (int) $link->remote_authorization_id === $row['id'], 409, 'Remote source registration changed.');
        SessionLink::whereKey($link->id)->whereNull('remote_authorization_id')->update(['remote_authorization_id' => $row['id']]);
        $set = ['status' => 'Accepted', 'realTimeAuth' => 'Never', 'realTimeAuthUrl' => self::CALLBACK, 'realTimeAuthTimeout' => 0, 'realTimeAuthLastAttempt' => null, 'cacheExpiryDateTime' => $expiry->toISOString(), 'concurrentTransaction' => false, 'isPrepaid' => false, 'updatedAt' => now()->toISOString()];
        $changed = $this->graph('mutation($id:Int!,$marker:jsonb!,$set:Authorizations_set_input!){update_Authorizations(where:{id:{_eq:$id},tenantId:{_eq:1},additionalInfo:{_eq:$marker}},_set:$set){affected_rows}}', ['id' => $row['id'], 'marker' => $row['additionalInfo'], 'set' => $set], $authority);
        abort_unless(($changed['update_Authorizations']['affected_rows'] ?? null) === 1, 409, 'Source ownership changed.');
        abort_unless($this->verified($link->fresh(), $tag, $authority, $expiry), 503, 'Remote publication requires inspection.');
    }

    /** Require a real absolute expiry, never Carbon's relative date grammar. */
    private function validSourceExpiry(mixed $value, ?CarbonImmutable $expected = null): bool
    {
        if (! is_string($value) || ! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value, $parts)) {
            return false;
        }
        $normalized = $parts[1].'.'.str_pad($parts[2] ?? '', 6, '0').($parts[3] === 'Z' ? '+00:00' : $parts[3]);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && CarbonImmutable::instance($parsed)->isFuture()
            && ($expected === null || CarbonImmutable::instance($parsed)->equalTo($expected));
    }

    public function verified(SessionLink $link, string $tag, ?array $authority = null, ?CarbonImmutable $expiry = null): bool
    {
        $authority ??= $this->sourceAuthority();
        $row = $this->source($link, $tag, $authority);
        if (! $row) {
            return false;
        }
        $this->owned($link, $row, $tag);

        return (int) $link->remote_authorization_id === $row['id'] && $row['status'] === 'Accepted' && $row['realTimeAuth'] === 'Never' && $row['realTimeAuthUrl'] === self::CALLBACK && $row['realTimeAuthTimeout'] === 0 && $row['concurrentTransaction'] === false && $row['isPrepaid'] === false && $this->validSourceExpiry($row['cacheExpiryDateTime'] ?? null, $expiry);
    }
}
