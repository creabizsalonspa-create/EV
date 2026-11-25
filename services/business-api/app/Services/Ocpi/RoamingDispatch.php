<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Charging\ChargingActorConsent;
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
        $expected = ['order_id' => $order->id, 'actor_id' => $order->user_id, 'request_key' => $key,
            'intent_hash' => $hash, 'action' => $action, 'callback_key' => $callback,
            'payload_ciphertext' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'state' => 'prepared'];
        $id = DB::table('ocpi_roaming_commands')->insertGetId($expected + ['created_at' => now(), 'updated_at' => now()]);

        // Preserve the approved bytes rather than trusting a second database
        // read as the original intent if the inserted claim was altered.
        return (object) ($expected + ['id' => $id]);
    }

    /** Match the durable command to the exact approved bytes and identity. */
    private function sameClaim(object $order, object $command, object $claim, string $action): bool
    {
        return (int) $claim->order_id === (int) $order->id
            && (int) $claim->actor_id === (int) $order->user_id
            && $claim->action === $action
            && $claim->request_key === $command->request_key
            && $claim->callback_key === $command->callback_key
            && hash_equals($command->intent_hash, $claim->intent_hash)
            && hash_equals($command->payload_ciphertext, $claim->payload_ciphertext);
    }

    private function preparedClaim(object $order, object $command, string $action, bool $lock = false): void
    {
        $query = DB::table('ocpi_roaming_commands')->where('id', $command->id);
        $claim = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($claim && $this->sameClaim($order, $command, $claim, $action)
            && $claim->state === 'prepared' && $claim->attempted_at === null
            && $claim->transport_result === null && $claim->callback_hash === null
            && $claim->callback_result === null && $claim->callback_at === null,
            409, 'Command claim changed; source reconciliation is required.');
    }

    private function preserveDispatchFailure(object $order, object $command, string $action, bool $wireAttempted): void
    {
        DB::transaction(function () use ($order, $command, $action, $wireAttempted) {
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $claim = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->first();
            if (! $claim || (int) $claim->order_id !== (int) $row->id || $claim->action !== $action
                || $claim->transport_result !== null || $claim->callback_hash !== null
                || $claim->callback_result !== null || $claim->callback_at !== null
                || ! in_array($claim->state, ['prepared', 'dispatching'], true)) {
                return;
            }
            $uncertain = $wireAttempted || $claim->attempted_at !== null
                || $claim->state !== 'prepared' || ! $this->sameClaim($row, $command, $claim, $action);
            DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update([
                'state' => $uncertain ? 'outcome_unknown' : 'not_dispatched', 'updated_at' => now(),
            ]);
        }, 3);
    }

    private function assertStartPayload(object $order, object $command, array $payload, SandboxRoaming $service, array $local): void
    {
        $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $token = $service->token($order);
        // The original token timestamp remains part of the signed intent.
        // Order state transitions legitimately advance updated_at.
        $token['last_updated'] = $payload['token']['last_updated'] ?? null;
        $expected = ['response_url' => $this->callback($local, $command->callback_key),
            'token' => $token, 'location_id' => $target['location_id'], 'evse_uid' => $target['evse_uid'],
            'connector_id' => $target['connector_id'], 'authorization_reference' => $order->authorization_reference];
        abort_unless($token['valid'] === true && $payload === $expected
            && is_string($token['last_updated']) && hash_equals($order->token_digest, hash('sha256', $token['uid'])), 409);
    }

    public function start(object $order): void
    {
        $service = app(SandboxRoaming::class);
        $command = DB::transaction(function () use ($order, $service) {
            User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
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
            [$provider, , $local] = $service->current($current, true);
            $this->preparedClaim($current, $command, 'START_SESSION');
            $payload = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($payload) && hash_equals($command->intent_hash,
                hash('sha256', json_encode([$current->id, 'START_SESSION', $payload], JSON_THROW_ON_ERROR))), 409);
            $this->assertStartPayload($current, $command, $payload, $service, $local);
            $token = $payload['token'];
            app(PartnerClient::class)->write($provider, 'tokens_receiver', 'PUT', [$token['country_code'], $token['party_id'], $token['uid']], $token);
            DB::table('ocpi_roaming_orders')->where('id', $current->id)->whereNull('token_revoked_at')->update(['token_state' => 'registered', 'updated_at' => now()]);
            $current = DB::table('ocpi_roaming_orders')->find($order->id);
            [$provider] = $service->current($current, true);
            $target = json_decode(Crypt::decryptString($current->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $offer = $service->offer(OcpiRecord::findOrFail($current->location_record_id), $target['evse_uid'], $target['connector_id'], $target['tariff_id']);
            abort_unless(hash_equals($current->offer_hash, $offer['hash']), 409);
            $provider = DB::transaction(function () use ($current, $command, $service, $payload) {
                User::whereKey($current->user_id)->lockForUpdate()->firstOrFail();
                OcpiProvider::whereKey($current->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $current->id)->lockForUpdate()->firstOrFail();
                [$provider, , $local] = $service->current($row, true);
                abort_unless($row->state === 'registering_token' && $row->token_state === 'registered' && $row->token_revoked_at === null, 409);
                $this->assertStartPayload($row, $command, $payload, $service, $local);
                $target = json_decode(Crypt::decryptString($row->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
                $offer = $service->offer(OcpiRecord::findOrFail($row->location_record_id), $target['evse_uid'], $target['connector_id'], $target['tariff_id']);
                abort_unless(hash_equals($row->offer_hash, $offer['hash']), 409);
                $this->preparedClaim($row, $command, 'START_SESSION', true);
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['state' => 'start_dispatching', 'updated_at' => now()]);
                DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()]);

                return $provider;
            }, 3);
            $startAttempted = true;
            $response = app(PartnerClient::class)->write($provider, 'commands_receiver', 'POST', ['START_SESSION'], $payload);
            $this->receipt($current, $command, $response);
        } catch (Throwable) {
            DB::transaction(function () use ($order, $command, $startAttempted) {
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $current = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                // Never erase a concurrent callback/receipt or release an EVSE
                // if the durable claim already contains ambiguous wire evidence.
                $claim = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->first();
                $known = $claim && $this->sameClaim($current, $command, $claim, 'START_SESSION');
                $uncertain = $startAttempted || ! $known || $current->authorized_at !== null
                    || ($claim && ($claim->state !== 'prepared' || $claim->attempted_at !== null
                        || $claim->transport_result !== null || $claim->callback_hash !== null
                        || $claim->callback_result !== null || $claim->callback_at !== null));
                if ($claim && (int) $claim->order_id === (int) $current->id && $claim->action === 'START_SESSION'
                    && in_array($claim->state, ['prepared', 'dispatching'], true) && $claim->transport_result === null
                    && $claim->callback_hash === null && $claim->callback_result === null && $claim->callback_at === null) {
                    DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update([
                        'state' => $uncertain ? 'outcome_unknown' : 'not_dispatched', 'updated_at' => now(),
                    ]);
                }
                if (! in_array($current->state, ['source_active', 'source_completed', 'partner_rejected'], true)) {
                    DB::table('ocpi_roaming_orders')->where('id', $current->id)->update([
                        'state' => $uncertain ? 'start_outcome_unknown' : 'not_dispatched',
                        'active_target_key' => $uncertain ? $current->active_target_key : null,
                        'token_state' => $current->token_state === 'dispatching' ? 'outcome_unknown' : $current->token_state,
                        'updated_at' => now(),
                    ]);
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
            abort_unless($this->sameClaim($row, $command, $cmd, $command->action) && $cmd->attempted_at !== null, 409);
            if ($cmd->transport_result !== null) {
                abort_unless($cmd->transport_result === $result, 409);

                return;
            }
            // An asynchronous receipt or prior token authorization may arrive
            // before a synchronous rejection. Preserve both facts and capacity
            // until source reconciliation; rejection alone no longer proves no start.
            $start = $command->action === 'START_SESSION';
            $conflicting = $result !== 'ACCEPTED' && (
                $cmd->callback_hash !== null || $cmd->callback_result !== null
                || $cmd->callback_at !== null || ($start && ($row->authorized_at !== null
                    || in_array($row->state, ['source_active', 'source_completed'], true)))
            );
            $state = $conflicting ? 'outcome_unknown' : ($result === 'ACCEPTED' ? 'transport_accepted' : 'partner_rejected');
            DB::table('ocpi_roaming_commands')->where('id', $cmd->id)->update(['transport_result' => $result, 'state' => $state, 'updated_at' => now()]);
            if ($start && ! in_array($row->state, ['source_active', 'source_completed'], true)) {
                $orderState = $conflicting ? 'start_outcome_unknown' : ($result === 'ACCEPTED' ? 'start_accepted' : 'partner_rejected');
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['state' => $orderState, 'active_target_key' => $orderState === 'partner_rejected' ? null : $row->active_target_key, 'updated_at' => now()]);
            }
        }, 3);
    }

    public function stop(User $actor, object $order, string $requestKey): void
    {
        $service = app(SandboxRoaming::class);
        $command = DB::transaction(function () use ($actor, $order, $requestKey, $service) {
            $user = app(ChargingActorConsent::class)->capture($actor);
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $current = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $user->id, 404);
            $old = DB::table('ocpi_roaming_commands')->where('actor_id', $user->id)->where('request_key', $requestKey)->first();
            if ($old) {
                abort_unless($old->order_id === $current->id && $old->action === 'STOP_SESSION', 409);

                return null;
            }
            [,,$local] = $service->current($current);
            abort_unless($current->state === 'source_active' && $current->source_session_record_id !== null, 409, 'Observe this customer-bound active partner session before requesting stop.');
            abort_if($service->stopPending($current->id), 409, 'A stop command already requires reconciliation.');
            $session = OcpiRecord::findOrFail($current->source_session_record_id);
            abort_unless(($session->normalized['status'] ?? null) === 'ACTIVE' && $service->ownsSourceSession($current, $session), 409, 'Refresh changed partner session ownership before stop.');
            $key = (string) Str::uuid();
            $command = $this->command($current, 'STOP_SESSION', $requestKey, ['response_url' => $this->callback($local, $key), 'session_id' => $session->external_id], $key);

            return $command;
        }, 3);
        if (! $command) {
            return;
        }
        $wireAttempted = false;
        try {
            $payload = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($command->intent_hash, hash('sha256', json_encode([$order->id, 'STOP_SESSION', $payload], JSON_THROW_ON_ERROR))), 409);
            $provider = DB::transaction(function () use ($actor, $order, $command, $service, $payload) {
                app(ChargingActorConsent::class)->capture($actor);
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                abort_unless($row->user_id === $actor->id && $row->state === 'source_active' && $row->source_session_record_id !== null, 409);
                $session = OcpiRecord::findOrFail($row->source_session_record_id);
                abort_unless(($session->normalized['status'] ?? null) === 'ACTIVE' && $session->external_id === $payload['session_id'] && $service->ownsSourceSession($row, $session), 409);
                [$provider, , $local] = $service->current($row);
                abort_unless(($payload['response_url'] ?? null) === $this->callback($local, $command->callback_key), 409);
                $this->preparedClaim($row, $command, 'STOP_SESSION', true);
                DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()]);

                return $provider;
            }, 3);
            $wireAttempted = true;
            $response = app(PartnerClient::class)->write($provider, 'commands_receiver', 'POST', ['STOP_SESSION'], $payload);
            $this->receipt($order, $command, $response);
        } catch (Throwable) {
            $this->preserveDispatchFailure($order, $command, 'STOP_SESSION', $wireAttempted);
        }
    }

    public function revokeToken(object $order, User $actor): void
    {
        $service = app(SandboxRoaming::class);
        $wireAttempted = false;
        try {
            $command = DB::transaction(function () use ($order, $service, $actor) {
                app(ChargingActorConsent::class)->capture($actor);
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $service->current($row);
                abort_unless($row->user_id === $actor->id && $row->token_revoked_at !== null, 409);
                $old = DB::table('ocpi_roaming_commands')->where('order_id', $row->id)->where('action', 'TOKEN_REVOKE')->lockForUpdate()->first();
                if ($old) {
                    if (! $service->knownUnsent($old)) {
                        return null;
                    }
                    // Explicit fresh-account completion reuses only proven-unsent
                    // immutable deactivation bytes, never an unknown wire outcome.
                    DB::table('ocpi_roaming_commands')->where('id', $old->id)->update(['state' => 'prepared', 'updated_at' => now()]);
                    DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'ocpi.source_deactivation_resumed', 'subject' => (string) $row->id, 'created_at' => now()]);

                    return DB::table('ocpi_roaming_commands')->find($old->id);
                }

                return $this->command($row, 'TOKEN_REVOKE', (string) Str::uuid(), $service->token($row, false), (string) Str::uuid());
            }, 3);
            if (! $command) {
                return;
            }
            $token = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($token) && ($token['valid'] ?? null) === false && hash_equals($command->intent_hash, hash('sha256', json_encode([$order->id, 'TOKEN_REVOKE', $token], JSON_THROW_ON_ERROR))), 409);
            $p = DB::transaction(function () use ($order, $actor, $command, $service, $token) {
                app(ChargingActorConsent::class)->capture($actor);
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                abort_unless($row->user_id === $actor->id && $row->token_revoked_at !== null, 409);
                [$provider] = $service->current($row);
                $expected = $service->token($row, false);
                // A proven-unsent retry retains its original source timestamp.
                unset($expected['last_updated']);
                $actual = $token;
                unset($actual['last_updated']);
                abort_unless($expected === $actual, 409);
                $this->preparedClaim($row, $command, 'TOKEN_REVOKE', true);
                DB::table('ocpi_roaming_commands')->where('id', $command->id)->update(['state' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()]);

                return $provider;
            }, 3);
            $wireAttempted = true;
            app(PartnerClient::class)->write($p, 'tokens_receiver', 'PUT', [$token['country_code'], $token['party_id'], $token['uid']], $token);
            DB::transaction(function () use ($order, $command) {
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $claim = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->sameClaim($row, $command, $claim, 'TOKEN_REVOKE') && $claim->attempted_at !== null
                    && $claim->state === 'dispatching' && $claim->transport_result === null
                    && $claim->callback_hash === null && $claim->callback_result === null && $claim->callback_at === null
                    && $row->token_revoked_at !== null, 409);
                DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update(['state' => 'transport_accepted', 'transport_result' => 'ACCEPTED', 'updated_at' => now()]);
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['token_state' => 'partner_revoked', 'updated_at' => now()]);
            }, 3);
        } catch (Throwable) {
            if (isset($command) && $command) {
                $this->preserveDispatchFailure($order, $command, 'TOKEN_REVOKE', $wireAttempted);
            }
        }
    }
}
