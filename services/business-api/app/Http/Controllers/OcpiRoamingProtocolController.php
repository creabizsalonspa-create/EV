<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\CredentialExchange;
use App\Services\Ocpi\SandboxRoaming;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OcpiRoamingProtocolController extends Controller
{
    private function reply(mixed $data, int $code = 1000, int $http = 200)
    {
        return response()->json(['status_code' => $code, 'timestamp' => now()->toISOString(), 'data' => $data], $http)->header('Cache-Control', 'private, no-store');
    }

    private function peer(Request $r): array
    {
        [$p,$link] = app(OcpiProtocolCredentialsController::class)->authenticate($r, app(CredentialExchange::class), 'roaming');
        app(SandboxRoaming::class)->policy($p);

        return [$p, $link];
    }

    public function callback(Request $r, string $key)
    {
        try {
            [$p,$link] = $this->peer($r);
            abort_unless(strlen($r->getContent()) <= 65536, 413);
            $body = $r->json()->all();
            $result = $body['result'] ?? null;
            abort_unless(in_array($result, ['ACCEPTED', 'REJECTED', 'EVSE_OCCUPIED', 'EVSE_INOPERATIVE', 'FAILED', 'NOT_SUPPORTED', 'TIMEOUT', 'UNKNOWN_SESSION'], true), 400);
            $command = DB::table('ocpi_roaming_commands as c')->join('ocpi_roaming_orders as o', 'o.id', '=', 'c.order_id')->where('o.provider_id', $p->id)->where('c.callback_key', $key)->whereIn('c.action', ['START_SESSION', 'STOP_SESSION'])->select('c.*')->first();
            abort_unless($command && $command->attempted_at, 404);
            $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
            DB::transaction(function () use ($r, $p, $link, $command, $result, $hash) {
                OcpiProvider::whereKey($p->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_credential_links')->where('id', $link->id)->lockForUpdate()->firstOrFail();
                [$current,$active] = $this->peer($r);
                abort_unless($active->generation === $link->generation, 401);
                $order = DB::table('ocpi_roaming_orders')->where('id', $command->order_id)->lockForUpdate()->firstOrFail();
                app(SandboxRoaming::class)->current($order);
                $row = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->firstOrFail();
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
                [, $active] = $this->peer($r);
                abort_unless($active->generation === $link->generation, 401);
                $candidate = DB::table('ocpi_roaming_orders')->where('provider_id', $p->id)->where('provider_owner_id', $p->owner_id)->where('counterparty_hash', $link->configuration_hash)->where('token_digest', hash('sha256', strtoupper($uid)))->first();
                abort_unless($candidate, 404);
                User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
                $order = DB::table('ocpi_roaming_orders')->where('id', $candidate->id)->lockForUpdate()->firstOrFail();
                $service = app(SandboxRoaming::class);
                $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
                $allowed = false;
                try {
                    $service->current($order, true);
                    $location = ($body['location_id'] ?? null) === $target['location_id'] && ($body['evse_uids'] ?? null) === [$target['evse_uid']];
                    $start = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', 'START_SESSION')->whereNotNull('attempted_at')->whereNotIn('state', ['not_dispatched', 'partner_rejected'])->exists();
                    if ($location && $start && $order->token_revoked_at === null && $order->state !== 'source_completed') {
                        if ($order->authorized_at !== null) {
                            $allowed = $order->authorization_request_key === $requestId && hash_equals($order->authorization_request_hash, $hash);
                        } elseif (in_array($order->state, ['start_dispatching', 'start_accepted', 'start_outcome_unknown'], true)) {
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
            $order = DB::table('ocpi_roaming_orders')->where('provider_id', $p->id)->where('provider_owner_id', $p->owner_id)->where('counterparty_hash', $link->configuration_hash)->where('token_digest', hash('sha256', strtoupper($uid)))->first();
            abort_unless($order, 404);
            $valid = false;
            try {
                app(SandboxRoaming::class)->current($order, true);
                $valid = $order->token_revoked_at === null && $order->authorized_at === null && in_array($order->state, ['start_dispatching', 'start_accepted', 'start_outcome_unknown'], true);
            } catch (Throwable) {
            }

            return $this->reply(app(SandboxRoaming::class)->token($order, $valid));
        } catch (Throwable $e) {
            $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 409;

            return $this->reply(null, $http === 404 ? 2004 : 2000, $http === 404 ? 200 : $http);
        }
    }

    public function tokens(Request $r)
    {
        try {
            [$p,$link] = $this->peer($r);
            $v = $r->validate(['offset' => 'sometimes|integer|min:0|max:100000', 'limit' => 'sometimes|integer|min:1|max:100', 'date_from' => 'sometimes|date', 'date_to' => 'sometimes|date|after_or_equal:date_from']);
            $query = DB::table('ocpi_roaming_orders')->where('provider_id', $p->id)->where('provider_owner_id', $p->owner_id)->where('counterparty_hash', $link->configuration_hash)->when(isset($v['date_from']), fn ($q) => $q->where('updated_at', '>=', $v['date_from']))->when(isset($v['date_to']), fn ($q) => $q->where('updated_at', '<', $v['date_to']));
            $total = (clone $query)->count();
            $rows = $query->orderBy('id')->offset($v['offset'] ?? 0)->limit($v['limit'] ?? 25)->get();
            $service = app(SandboxRoaming::class);
            $data = [];
            foreach ($rows as $order) {
                $valid = false;
                try {
                    $service->current($order, true);
                    $valid = $order->token_revoked_at === null && $order->authorized_at === null && in_array($order->state, ['start_dispatching', 'start_accepted', 'start_outcome_unknown'], true);
                } catch (Throwable) {
                }$data[] = $service->token($order, $valid);
            }
            $reply = $this->reply($data)->header('X-Total-Count', (string) $total)->header('X-Limit', (string) ($v['limit'] ?? 25));
            $offset = (int) ($v['offset'] ?? 0);
            $limit = (int) ($v['limit'] ?? 25);
            if ($offset + $limit < $total) {
                $local = app(CredentialExchange::class)->localPayload('');
                $base = substr($local['url'], 0, -strlen('versions')).'2.2.1/tokens';
                $reply->header('Link', '<'.$base.'?'.http_build_query(array_merge($v, ['offset' => $offset + $limit, 'limit' => $limit])).'>; rel="next"');
            }

            return $reply;
        } catch (Throwable $e) {
            $http = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 409;

            return $this->reply(null, 2000, $http);
        }
    }
}
