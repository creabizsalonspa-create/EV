<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\OcpiMessageHeaders;
use App\Services\Ocpi\SandboxRoaming;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OcpiRoamingProtocolController extends Controller
{
    private function reply(mixed $data, int $code = 1000, int $http = 200)
    {
        $ids = OcpiMessageHeaders::response(request());

        return response()->json(['status_code' => $code, 'timestamp' => now()->toISOString(), 'data' => $data], $http)->header('Cache-Control', 'private, no-store')->header('X-Request-ID', $ids['X-Request-ID'])->header('X-Correlation-ID', $ids['X-Correlation-ID']);
    }

    private function peer(Request $r): array
    {
        [$p,$link] = app(OcpiProtocolCredentialsController::class)->authenticate($r, app(CredentialExchange::class), 'roaming');
        app(SandboxRoaming::class)->policy($p);

        return [$p, $link];
    }

    private function hasAttemptedGrant(object $order): bool
    {
        $action = ($order->intent_kind ?? 'start') === 'reservation' ? 'RESERVE_NOW' : 'START_SESSION';
        $claims = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)
            ->where('action', $action)->limit(2)->get();
        if ($claims->count() !== 1) {
            return false;
        }
        $claim = $claims->first();
        if ((int) $claim->actor_id !== (int) $order->user_id || $claim->attempted_at === null
            || ! in_array($claim->state, ['dispatching', 'transport_accepted', 'outcome_unknown'], true)
            || ($claim->transport_result !== null && $claim->transport_result !== 'ACCEPTED')
            || ($claim->callback_result !== null && $claim->callback_result !== 'ACCEPTED')) {
            return false;
        }
        try {
            $payload = json_decode(Crypt::decryptString($claim->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $service = app(SandboxRoaming::class);
            [, , $local] = $service->current($order, true);
            $expected = $service->token($order);
            $token = $payload['token'] ?? [];
            $expected['last_updated'] = $token['last_updated'] ?? null;
            $callback = substr($local['url'], 0, -strlen('versions')).'2.2.1/commands/'.$claim->callback_key;

            return hash_equals($claim->intent_hash, hash('sha256', json_encode([$order->id, $action, $payload], JSON_THROW_ON_ERROR)))
                && $claim->request_key === $order->request_key
                && ($payload['response_url'] ?? null) === $callback
                && ($payload['authorization_reference'] ?? null) === $order->authorization_reference
                && ($payload['location_id'] ?? null) === $target['location_id']
                && ($payload['evse_uid'] ?? null) === $target['evse_uid']
                && ($action === 'START_SESSION'
                    ? ($payload['connector_id'] ?? null) === $target['connector_id']
                    : ($payload['reservation_id'] ?? null) === $order->reservation_id
                        && ($payload['expiry_date'] ?? null) === CarbonImmutable::parse($order->reservation_expires_at, 'UTC')->toISOString())
                && $expected === $token && $token['valid'] === true
                && is_string($token['last_updated'])
                && hash_equals($order->token_digest, hash('sha256', $token['uid']));
        } catch (Throwable) {
            return false;
        }
    }

    private function tokenValid(object $order, SandboxRoaming $service): bool
    {
        try {
            $service->current($order, true);

            return $order->token_revoked_at === null && $order->authorized_at === null
                && in_array($order->state, ['start_dispatching', 'start_accepted', 'start_outcome_unknown', 'reservation_dispatching', 'reservation_accepted', 'reservation_outcome_unknown', 'source_reserved'], true)
                && $this->hasAttemptedGrant($order);
        } catch (Throwable) {
            return false;
        }
    }

    /** A stored callback key alone is not evidence of an intact dispatched intent. */
    private function assertCallbackIntent(object $order, object $claim, array $local): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($claim->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $expectedUrl = substr($local['url'], 0, -strlen('versions')).'2.2.1/commands/'.$claim->callback_key;
            $valid = is_array($payload) && ! array_is_list($payload)
                && ($payload['response_url'] ?? null) === $expectedUrl
                && hash_equals($claim->intent_hash, hash('sha256', json_encode([$order->id, $claim->action, $payload], JSON_THROW_ON_ERROR)));
        } catch (Throwable) {
            $valid = false;
        }
        abort_unless($valid, 409, 'Command intent requires source reconciliation.');
    }

    public function callback(Request $r, string $key)
    {
        try {
            [$p,$link] = $this->peer($r);
            abort_unless(strlen($r->getContent()) <= 65536, 413);
            $body = $r->json()->all();
            $result = $body['result'] ?? null;
            abort_unless(in_array($result, ['ACCEPTED', 'REJECTED', 'EVSE_OCCUPIED', 'EVSE_INOPERATIVE', 'FAILED', 'NOT_SUPPORTED', 'TIMEOUT', 'UNKNOWN_SESSION', 'UNKNOWN_RESERVATION', 'CANCELED_RESERVATION'], true), 400);
            $command = DB::table('ocpi_roaming_commands as c')->join('ocpi_roaming_orders as o', 'o.id', '=', 'c.order_id')->where('o.provider_id', $p->id)->where('c.callback_key', $key)->whereIn('c.action', ['START_SESSION', 'STOP_SESSION', 'RESERVE_NOW', 'CANCEL_RESERVATION'])->select('c.*')->first();
            abort_unless($command && $command->attempted_at, 404);
            abort_unless($result !== 'CANCELED_RESERVATION' || $command->action === 'RESERVE_NOW', 400);
            abort_unless($result !== 'UNKNOWN_RESERVATION' || $command->action === 'CANCEL_RESERVATION', 400);
            $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
            DB::transaction(function () use ($r, $p, $link, $command, $key, $result, $hash) {
                OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                [$current,$active] = $this->peer($r);
                abort_unless($current->id === $p->id && $active->id === $link->id && $active->generation === $link->generation, 401);
                $order = DB::table('ocpi_roaming_orders')->where('id', $command->order_id)->lockForUpdate()->firstOrFail();
                [, , $local] = app(SandboxRoaming::class)->current($order);
                $row = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $order->provider_id === (int) $current->id
                    && (int) $row->order_id === (int) $order->id
                    && (int) $row->actor_id === (int) $order->user_id
                    && $row->callback_key === $key && $row->action === $command->action
                    && $row->attempted_at !== null && $row->attempted_at === $command->attempted_at
                    && $row->request_key === $command->request_key
                    && hash_equals($command->intent_hash, $row->intent_hash)
                    && hash_equals($command->payload_ciphertext, $row->payload_ciphertext), 409);
                $this->assertCallbackIntent($order, $row, $local);
                if ($result === 'CANCELED_RESERVATION') {
                    abort_unless(($order->intent_kind ?? 'start') === 'reservation', 409);
                    if ($order->reservation_cancel_hash !== null) {
                        abort_unless(hash_equals($order->reservation_cancel_hash, $hash), 409);

                        return;
                    }
                    DB::table('ocpi_roaming_orders')->where('id', $order->id)->update(['reservation_cancel_hash' => $hash, 'reservation_cancelled_at' => now(), 'token_revoked_at' => $order->token_revoked_at ?? now(), 'token_state' => $order->token_state === 'partner_revoked' ? 'partner_revoked' : 'local_revoked', 'updated_at' => now()]);

                    return;
                }
                if ($row->callback_hash !== null) {
                    abort_unless(hash_equals($row->callback_hash, $hash), 409);

                    return;
                }
                DB::table('ocpi_roaming_commands')->where('id', $row->id)->update(['callback_result' => $result, 'callback_hash' => $hash, 'callback_at' => now(), 'updated_at' => now()]);
                // Command result is never a source session, final meter or billing fact.
            }, 3);

            return $this->reply(null);
        } catch (Throwable $e) {
            $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 409;

            return $this->reply(null, in_array($http, [401, 403], true) ? 2000 : 2001, $http);
        }
    }

    public function authorize(Request $r, string $uid)
    {
        try {
            [$p,$link] = $this->peer($r);
            abort_unless(preg_match('/^[A-Za-z0-9]{1,36}$/D', $uid) && $r->query('type', 'APP_USER') === 'APP_USER' && strlen($r->getContent()) <= 65536, 400);
            $body = $r->json()->all();
            $requestId = $r->header('X-Request-ID');
            abort_unless(is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $requestId), 400);
            $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
            $response = DB::transaction(function () use ($r, $p, $link, $uid, $body, $requestId, $hash) {
                OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                [$current, $active] = $this->peer($r);
                abort_unless($current->id === $p->id && $active->id === $link->id && $active->generation === $link->generation, 401);
                $candidate = DB::table('ocpi_roaming_orders')->where('provider_id', $p->id)->where('provider_owner_id', $p->owner_id)->where('counterparty_hash', $link->configuration_hash)->where('token_digest', hash('sha256', strtoupper($uid)))->first();
                abort_unless($candidate, 404);
                User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
                $order = DB::table('ocpi_roaming_orders')->where('id', $candidate->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $order->provider_id === (int) $current->id
                    && (int) $order->provider_owner_id === (int) $current->owner_id
                    && hash_equals($active->configuration_hash, $order->counterparty_hash)
                    && hash_equals($order->token_digest, hash('sha256', strtoupper($uid))), 404);
                $service = app(SandboxRoaming::class);
                $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
                $allowed = false;
                try {
                    $service->current($order, true);
                    $location = ($body['location_id'] ?? null) === $target['location_id'] && ($body['evse_uids'] ?? null) === [$target['evse_uid']];
                    $start = $this->hasAttemptedGrant($order);
                    if ($location && $start && $order->token_revoked_at === null && $order->state !== 'source_completed') {
                        if ($order->authorized_at !== null) {
                            $allowed = $order->authorization_request_key === $requestId && hash_equals($order->authorization_request_hash, $hash);
                        } elseif (in_array($order->state, ['start_dispatching', 'start_accepted', 'start_outcome_unknown', 'reservation_dispatching', 'reservation_accepted', 'reservation_outcome_unknown', 'source_reserved'], true)) {
                            $allowed = true;
                            DB::table('ocpi_roaming_orders')->where('id', $order->id)->update(['authorized_at' => now(), 'authorization_request_key' => $requestId, 'authorization_request_hash' => $hash, 'updated_at' => now()]);
                        }
                    }
                } catch (Throwable) {
                }

                return ['allowed' => $allowed ? 'ALLOWED' : 'NOT_ALLOWED', 'token' => $service->token($order, $allowed), 'authorization_reference' => $order->authorization_reference];
            }, 3);

            return $this->reply($response);
        } catch (Throwable $e) {
            $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 409;

            return $this->reply(null, $http === 404 ? 2004 : 2000, $http === 404 ? 200 : $http);
        }
    }

    public function token(Request $r, string $country, string $party, string $uid)
    {
        try {
            [$p,$link] = $this->peer($r);
            [, $local] = app(SandboxRoaming::class)->policy($p);
            abort_unless($country === $local['roles'][0]['country_code'] && $party === $local['roles'][0]['party_id'] && $r->query('type', 'APP_USER') === 'APP_USER' && preg_match('/^[A-Za-z0-9]{1,36}$/D', $uid), 404);
            $data = DB::transaction(function () use ($r, $p, $link, $uid) {
                OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                [$current, $active] = $this->peer($r);
                abort_unless($current->id === $p->id && $active->id === $link->id && $active->generation === $link->generation, 401);
                $order = DB::table('ocpi_roaming_orders')->where('provider_id', $current->id)->where('provider_owner_id', $current->owner_id)->where('counterparty_hash', $active->configuration_hash)->where('token_digest', hash('sha256', strtoupper($uid)))->lockForUpdate()->first();
                abort_unless($order, 404);
                $service = app(SandboxRoaming::class);

                return $service->token($order, $this->tokenValid($order, $service));
            }, 3);

            return $this->reply($data);
        } catch (Throwable $e) {
            $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 409;

            return $this->reply(null, $http === 404 ? 2004 : 2000, $http === 404 ? 200 : $http);
        }
    }

    public function tokens(Request $r)
    {
        try {
            [$p,$link] = $this->peer($r);
            $v = $r->validate(['offset' => 'sometimes|integer|min:0|max:100000', 'limit' => 'sometimes|integer|min:1|max:100', 'date_from' => 'sometimes|required|string|max:40', 'date_to' => 'sometimes|required|string|max:40']);
            // OCPI token window bounds may be absent, but a supplied bound
            // must be a complete date-time instant. Laravel's generic date
            // rule accepts relative text ("tomorrow") and invalid calendar
            // rollovers. Never turn such input into an authorized bulk read.
            // Explicit numeric offsets are normalized to UTC for existing
            // managed peer compatibility; Z-free values are UTC, not local.
            $utc = static function (string $value): string {
                abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-3]):[0-5]\d|[+-]14:00)?$/D', $value) === 1, 422);
                $zoned = str_ends_with($value, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/D', $value);
                $format = '!Y-m-d\TH:i:s'
                    .(str_contains($value, '.') ? '.u' : '')
                    .($zoned ? 'P' : '');
                $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
                abort_unless($date !== false && \DateTimeImmutable::getLastErrors() === false, 422);
                $time = CarbonImmutable::instance($date)->utc();

                return $time->format($time->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
            };
            $from = isset($v['date_from']) ? $utc($v['date_from']) : null;
            $to = isset($v['date_to']) ? $utc($v['date_to']) : null;
            abort_if($from !== null && $to !== null && $to < $from, 422);
            [$data, $total] = DB::transaction(function () use ($r, $p, $link, $from, $to, $v) {
                OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                [$current, $active] = $this->peer($r);
                abort_unless($current->id === $p->id && $active->id === $link->id && $active->generation === $link->generation, 401);
                $query = DB::table('ocpi_roaming_orders')->where('provider_id', $current->id)->where('provider_owner_id', $current->owner_id)->where('counterparty_hash', $active->configuration_hash)->when($from !== null, fn ($q) => $q->where('updated_at', '>=', $from))->when($to !== null, fn ($q) => $q->where('updated_at', '<', $to));
                $total = (clone $query)->count();
                $rows = $query->orderBy('id')->offset($v['offset'] ?? 0)->limit($v['limit'] ?? 25)->lockForUpdate()->get();
                $service = app(SandboxRoaming::class);
                $data = [];
                foreach ($rows as $order) {
                    $data[] = $service->token($order, $this->tokenValid($order, $service));
                }

                return [$data, $total];
            }, 3);
            $reply = $this->reply($data)->header('X-Total-Count', (string) $total)->header('X-Limit', (string) ($v['limit'] ?? 25));
            $offset = (int) ($v['offset'] ?? 0);
            $limit = (int) ($v['limit'] ?? 25);
            if ($offset + $limit < $total) {
                $local = app(CredentialExchange::class)->localPayload('', $p);
                $base = substr($local['url'], 0, -strlen('versions')).'2.2.1/tokens';
                $reply->header('Link', '<'.$base.'?'.http_build_query(array_merge($v, ['offset' => $offset + $limit, 'limit' => $limit])).'>; rel="next"');
            }

            return $reply;
        } catch (Throwable $e) {
            // Laravel validation failures are domain-safe 422 responses, not
            // generic 409 upstream conflicts. Keep details and source data
            // private while preserving OCPI tracing on malformed peer input.
            $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode()
                : ($e instanceof ValidationException ? 422 : 409);

            return $this->reply(null, 2000, $http);
        }
    }
}
