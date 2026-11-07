<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** No queue retry: command identity is durably claimed before the only outbound attempt. */
class RoamingDispatch
{
    private function callback(array $local, string $key): string
    {
        return substr($local['url'], 0, -strlen('versions')).'2.2.1/commands/'.$key;
    }

    private function command(object $order, string $action, string $key, array $payload, string $callback): object
    {
        $hash = hash('sha256', json_encode([$order->id, $action, $payload], JSON_THROW_ON_ERROR));
        $id = DB::table('ocpi_roaming_commands')->insertGetId(['order_id' => $order->id, 'actor_id' => $order->user_id, 'request_key' => $key, 'intent_hash' => $hash, 'action' => $action, 'callback_key' => $callback, 'payload_ciphertext' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'state' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('ocpi_roaming_commands')->find($id);
    }

    public function start(object $order): void
    {
        $service = app(SandboxRoaming::class);
        $command = DB::transaction(function () use ($order, $service) {
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $current = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('ocpi_roaming_commands')->where('order_id', $current->id)->where('action', 'START_SESSION')->first();
            if ($existing) {
                return null;
            }
            [,,$local] = $service->current($current, true);
            abort_unless($current->state === 'prepared', 409);
            $target = json_decode(Crypt::decryptString($current->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $key = (string) Str::uuid();
            $payload = ['response_url' => $this->callback($local, $key), 'token' => $service->token($current), 'location_id' => $target['location_id'], 'evse_uid' => $target['evse_uid'], 'connector_id' => $target['connector_id'], 'authorization_reference' => $current->authorization_reference];
            DB::table('ocpi_roaming_orders')->where('id', $current->id)->update(['state' => 'registering_token', 'token_state' => 'dispatching', 'updated_at' => now()]);

            return $this->command($current, 'START_SESSION', $current->request_key, $payload, $key);
        }, 3);
        if (! $command) {
            return;
        }
        $startAttempted = false;
        try {
            $current = DB::table('ocpi_roaming_orders')->find($order->id);
            [$provider,,$local] = $service->current($current, true);
            $payload = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $token = $payload['token'];
            app(PartnerClient::class)->write($provider, 'tokens_receiver', 'PUT', [$token['country_code'], $token['party_id'], $token['uid']], $token);
            DB::table('ocpi_roaming_orders')->where('id', $current->id)->whereNull('token_revoked_at')->update(['token_state' => 'registered', 'updated_at' => now()]);
            $current = DB::table('ocpi_roaming_orders')->find($order->id);
            [$provider] = $service->current($current, true);
            $target = json_decode(Crypt::decryptString($current->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $offer = $service->offer(OcpiRecord::findOrFail($current->location_record_id), $target['evse_uid'], $target['connector_id'], $target['tariff_id']);
            abort_unless(hash_equals($current->offer_hash, $offer['hash']), 409);
            DB::transaction(function () use ($current, $command, $service) {
                OcpiProvider::whereKey($current->provider_id)->lockForUpdate()->firstOrFail();
                User::whereKey($current->user_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $current->id)->lockForUpdate()->firstOrFail();
                $service->current($row, true);
                abort_unless($row->state === 'registering_token' && $row->token_revoked_at === null, 409);
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['state' => 'start_dispatching', 'updated_at' => now()]);
                DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()]);
            }, 3);
            $startAttempted = true;
            $response = app(PartnerClient::class)->write($provider, 'commands_receiver', 'POST', ['START_SESSION'], $payload);
            $this->receipt($current, $command, $response);
        } catch (Throwable) {
            DB::transaction(function () use ($order, $command, $startAttempted) {
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $current = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                DB::table('ocpi_roaming_commands')->where('id', $command->id)->whereNull('transport_result')->update(['state' => $startAttempted ? 'outcome_unknown' : 'not_dispatched', 'updated_at' => now()]);
                if ($current->token_revoked_at === null && ! in_array($current->state, ['source_active', 'source_completed'], true)) {
                    DB::table('ocpi_roaming_orders')->where('id', $current->id)->update(['state' => $startAttempted ? 'start_outcome_unknown' : 'not_dispatched', 'active_target_key' => $startAttempted ? $current->active_target_key : null, 'token_state' => $current->token_state === 'dispatching' ? 'outcome_unknown' : $current->token_state, 'updated_at' => now()]);
                }
            }, 3);
        }
    }

    private function receipt(object $order, object $command, array $response): void
    {
        $data = $response['data'] ?? null;
        $result = is_array($data) ? ($data['result'] ?? null) : null;
        abort_unless(in_array($result, ['ACCEPTED', 'REJECTED', 'NOT_SUPPORTED', 'UNKNOWN_SESSION'], true), 409, 'Unrecognized partner command receipt.');
        DB::transaction(function () use ($order, $command, $result) {
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $cmd = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->firstOrFail();
            if ($cmd->transport_result !== null) {
                abort_unless($cmd->transport_result === $result, 409);

                return;
            }
            DB::table('ocpi_roaming_commands')->where('id', $cmd->id)->update(['transport_result' => $result, 'state' => $result === 'ACCEPTED' ? 'transport_accepted' : 'partner_rejected', 'updated_at' => now()]);
            if ($command->action === 'START_SESSION' && ! in_array($row->state, ['source_active', 'source_completed'], true)) {
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['state' => $result === 'ACCEPTED' ? 'start_accepted' : 'partner_rejected', 'active_target_key' => $result === 'ACCEPTED' ? $row->active_target_key : null, 'updated_at' => now()]);
            }
        }, 3);
    }

    public function stop(User $actor, object $order, string $requestKey): void
    {
        $service = app(SandboxRoaming::class);
        $command = DB::transaction(function () use ($actor, $order, $requestKey, $service) {
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $user->id, 404);
            $old = DB::table('ocpi_roaming_commands')->where('actor_id', $user->id)->where('request_key', $requestKey)->first();
            if ($old) {
                abort_unless($old->order_id === $current->id && $old->action === 'STOP_SESSION', 409);

                return null;
            }
            [,,$local] = $service->current($current);
            abort_unless($current->state === 'source_active' && $current->source_session_record_id !== null, 409, 'Observe this customer-bound active partner session before requesting stop.');
            abort_if(DB::table('ocpi_roaming_commands')->where('order_id', $current->id)->where('action', 'STOP_SESSION')->whereNotIn('state', ['partner_rejected'])->exists(), 409, 'A stop command already requires reconciliation.');
            $session = OcpiRecord::findOrFail($current->source_session_record_id);
            abort_unless(($session->normalized['status'] ?? null) === 'ACTIVE' && $service->ownsSourceSession($current, $session), 409, 'Refresh changed partner session ownership before stop.');
            $key = (string) Str::uuid();
            $command = $this->command($current, 'STOP_SESSION', $requestKey, ['response_url' => $this->callback($local, $key), 'session_id' => $session->external_id], $key);
            DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()]);

            return $command;
        }, 3);
        if (! $command) {
            return;
        }
        try {
            [$provider] = $service->current(DB::table('ocpi_roaming_orders')->find($order->id));
            $payload = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $response = app(PartnerClient::class)->write($provider, 'commands_receiver', 'POST', ['STOP_SESSION'], $payload);
            $this->receipt($order, $command, $response);
        } catch (Throwable) {
            DB::table('ocpi_roaming_commands')->where('id', $command->id)->whereNull('transport_result')->update(['state' => 'outcome_unknown', 'updated_at' => now()]);
        }
    }

    public function revokeToken(object $order): void
    {
        $service = app(SandboxRoaming::class);
        try {
            $command = DB::transaction(function () use ($order, $service) {
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $service->current($row);
                abort_unless($row->token_revoked_at !== null, 409);
                if (DB::table('ocpi_roaming_commands')->where('order_id', $row->id)->where('action', 'TOKEN_REVOKE')->exists()) {
                    return null;
                }
                $command = $this->command($row, 'TOKEN_REVOKE', (string) Str::uuid(), $service->token($row, false), (string) Str::uuid());
                DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()]);

                return $command;
            }, 3);
            if (! $command) {
                return;
            }
            [$p] = $service->current(DB::table('ocpi_roaming_orders')->find($order->id));
            $token = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            app(PartnerClient::class)->write($p, 'tokens_receiver', 'PUT', [$token['country_code'], $token['party_id'], $token['uid']], $token);
            DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'transport_accepted', 'transport_result' => 'ACCEPTED', 'updated_at' => now()]);
            DB::table('ocpi_roaming_orders')->where('id', $order->id)->update(['token_state' => 'partner_revoked', 'updated_at' => now()]);
        } catch (Throwable) {
            if (isset($command) && $command) {
                DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'outcome_unknown', 'updated_at' => now()]);
            }
        }
    }
}
