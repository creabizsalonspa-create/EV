<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use App\Services\Charging\ChargingActorConsent;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Approved sandbox reservations share canonical order/token/EVSE capacity and never bill. */
final class ReservationRoaming
{
    public const TERMS = 'sandbox-reservation-preauthorization-v1';

    public function policy(OcpiProvider $p): array
    {
        $policy = app(SandboxRoaming::class)->policy($p);
        abort_unless(app(ContractReviewGate::class)->current($p), 409, 'Current internal partner review is required for a new reservation.');
        $c = $policy[0];
        abort_unless(($c['test_reservations'] ?? false) === true && is_string($c['test_reservation_contract'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $c['test_reservation_contract']), 409, 'Explicit approved sandbox reservation contract is required.');

        return $policy;
    }

    public function supported(OcpiProvider $p): bool
    {
        try {
            $this->policy($p);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A sandbox contract does not imply every published EVSE is reservable.
     * OCPI 2.2.1 EVSE.capabilities must explicitly advertise RESERVABLE.
     * Missing, malformed or duplicate EVSE source identities fail closed.
     */
    public function sourceUtcInstant(mixed $value): ?CarbonImmutable
    {
        // Strict OCPI 2.2.1 UTC DateTime (Z optional) without PHP calendar
        // rollover, local-server timezone interpretation or offset coercion.
        if (! is_string($value) || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?Z?$/D', $value) !== 1) {
            return null;
        }
        $format = '!Y-m-d\\TH:i:s'.(str_contains($value, '.') ? '.u' : '').(str_ends_with($value, 'Z') ? '\\Z' : '');
        $instant = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        if ($instant === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return CarbonImmutable::instance($instant);
    }

    public function reservableEvse(OcpiRecord $location, string $evseUid, string $connectorId): bool
    {
        if ($location->module !== 'locations') {
            return false;
        }
        $evses = $location->payload['evses'] ?? null;
        if (! is_array($evses) || ! array_is_list($evses)) {
            return false;
        }
        $matches = array_values(array_filter($evses, fn ($evse) => is_array($evse) && ($evse['uid'] ?? null) === $evseUid));
        if (count($matches) !== 1 || ($matches[0]['status'] ?? null) !== 'AVAILABLE') {
            return false;
        }
        $evse = $matches[0];
        $capabilities = $evse['capabilities'] ?? null;
        if (! is_array($capabilities) || ! array_is_list($capabilities) || ! in_array('RESERVABLE', $capabilities, true)) {
            return false;
        }
        $connectors = $evse['connectors'] ?? null;
        if (! is_array($connectors) || ! array_is_list($connectors)) {
            return false;
        }
        $selected = array_values(array_filter($connectors, fn ($connector) => is_array($connector) && ($connector['id'] ?? null) === $connectorId));
        if (count($selected) !== 1) {
            return false;
        }
        $evseTime = $this->sourceUtcInstant($evse['last_updated'] ?? null);
        $connectorTime = $this->sourceUtcInstant($selected[0]['last_updated'] ?? null);
        if ($evseTime === null || $connectorTime === null) {
            return false;
        }
        $now = now();
        $cutoff = $now->copy()->subMinutes(5);

        // All three OCPI timestamps must describe a coherent, recently
        // reported connector. This is only source freshness evidence:
        // physical occupancy and charging authorization stay unverified.
        return $evseTime->betweenIncluded($cutoff, $now)
            && $connectorTime->betweenIncluded($cutoff, $now)
            && $connectorTime->lte($evseTime)
            && $evseTime->lte($location->source_updated_at);
    }

    /** A Location refresh alone does not prove that its selected EVSE became free. */
    private function hasPostClosureConnectorEvidence(OcpiRecord $location, array $target, CarbonImmutable $cutoff): bool
    {
        $evses = $location->payload['evses'] ?? null;
        if (! is_array($evses) || ! array_is_list($evses)) {
            return false;
        }
        $matches = array_values(array_filter($evses, fn ($evse) => is_array($evse) && ($evse['uid'] ?? null) === $target['evse_uid']));
        if (count($matches) !== 1 || ($matches[0]['status'] ?? null) !== 'AVAILABLE') {
            return false;
        }
        $evse = $matches[0];
        $connectors = $evse['connectors'] ?? null;
        if (! is_array($connectors) || ! array_is_list($connectors)) {
            return false;
        }
        $selected = array_values(array_filter($connectors, fn ($connector) => is_array($connector) && ($connector['id'] ?? null) === $target['connector_id']));
        if (count($selected) !== 1) {
            return false;
        }
        $evseTime = $this->sourceUtcInstant($evse['last_updated'] ?? null);
        $connectorTime = $this->sourceUtcInstant($selected[0]['last_updated'] ?? null);

        return $evseTime !== null && $connectorTime !== null
            && $connectorTime->gt($cutoff) && $evseTime->gt($cutoff)
            && $connectorTime->gte(now()->subMinutes(5))
            && $connectorTime->lte($evseTime)
            && $evseTime->lte($location->source_updated_at);
    }

    public function sessions(object $order): Collection
    {
        $rows = OcpiRecord::where('ocpi_provider_id', $order->provider_id)->where('module', 'sessions')->where('id', '>', $order->source_record_floor)->orderByDesc('id')->limit(1001)->get();
        abort_if($rows->count() > 1000, 409, 'Bounded source review is required.');

        return $rows->filter(fn ($r) => app(SandboxRoaming::class)->ownsSourceSession($order, $r));
    }

    private function pending(int $id): bool
    {
        return DB::table('ocpi_roaming_commands')->where('order_id', $id)->where('action', 'CANCEL_RESERVATION')->whereNotIn('state', ['partner_rejected', 'not_dispatched'])->exists()
         || DB::table('ocpi_roaming_commands')->where('order_id', $id)->where('action', 'CANCEL_RESERVATION')->where('state', 'not_dispatched')->where(fn ($q) => $q->whereNotNull('attempted_at')->orWhereNotNull('transport_result')->orWhereNotNull('callback_hash')->orWhereNotNull('callback_result')->orWhereNotNull('callback_at'))->exists()
            || DB::table('ocpi_roaming_commands')->where('order_id', $id)->where('action', 'CANCEL_RESERVATION')->where('state', 'partner_rejected')->where(fn ($q) => $q->whereNotNull('callback_hash')->orWhereNotNull('callback_result')->orWhereNotNull('callback_at'))->exists();
    }

    public function hints(object $order, bool $driver): array
    {
        $is = ($order->intent_kind ?? 'start') === 'reservation';
        $can = false;
        if ($is && $driver && $order->reservation_cancelled_at === null && $order->active_target_key !== null && ! in_array($order->source_status, ['ACTIVE', 'COMPLETED'], true) && ! $this->pending($order->id)) {
            try {
                app(SandboxRoaming::class)->current($order);
                $can = true;
            } catch (Throwable) {
            }
        }

        return ['intent_kind' => $is ? 'reservation' : 'start', 'reservation_expires_at' => $is ? CarbonImmutable::parse($order->reservation_expires_at, 'UTC')->toISOString() : null, 'reservation_source_cancelled' => $is && $order->reservation_cancelled_at !== null, 'reservation_closed' => $is && $order->reservation_closed_at !== null, 'can_cancel_reservation' => $can, 'reservation_billing_supported' => false];
    }

    public function reserve(User $actor, object $order): void
    {
        $this->send($actor, $order, 'RESERVE_NOW', $order->request_key);
    }

    public function cancel(User $actor, object $order, string $key): void
    {
        $fresh = DB::transaction(function () use ($actor, $order, $key) {
            app(ChargingActorConsent::class)->capture($actor);
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->user_id === $actor->id && $row->intent_kind === 'reservation', 404);
            app(SandboxRoaming::class)->current($row);
            $old = DB::table('ocpi_roaming_commands')->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($old) {
                abort_unless($old->order_id === $row->id && $old->action === 'CANCEL_RESERVATION', 409);

                return false;
            }
            abort_unless($row->active_target_key !== null && $row->reservation_cancelled_at === null, 409);
            abort_if($this->pending($row->id), 409);
            abort_if($this->sessions($row)->contains(fn ($s) => in_array($s->normalized['status'], ['ACTIVE', 'COMPLETED'], true)), 409, 'Reconcile the owned charging session and use its stop workflow. Reservation cancellation is not a charging stop.');
            if ($row->token_revoked_at === null) {
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['token_revoked_at' => now(), 'token_state' => 'local_revoked', 'updated_at' => now()]);
            }

            return true;
        }, 3);
        if (! $fresh) {
            return;
        }
        app(RoamingDispatch::class)->revokeToken(DB::table('ocpi_roaming_orders')->find($order->id), $actor);
        $this->send($actor, $order, 'CANCEL_RESERVATION', $key);
    }

    private function sameClaim(object $row, object $command, object $order, string $action): bool
    {
        return (int) $row->order_id === (int) $order->id
            && (int) $row->actor_id === (int) $order->user_id
            && $row->action === $action
            && $row->request_key === $command->request_key
            && $row->callback_key === $command->callback_key
            && hash_equals($row->intent_hash, $command->intent_hash)
            && hash_equals($row->payload_ciphertext, $command->payload_ciphertext);
    }

    private function payload(object $row, string $action, string $callback, array $local, SandboxRoaming $service): array
    {
        $payload = ['response_url' => substr($local['url'], 0, -strlen('versions')).'2.2.1/commands/'.$callback,
            'reservation_id' => $row->reservation_id];
        if ($action === 'RESERVE_NOW') {
            $target = json_decode(Crypt::decryptString($row->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $payload += ['token' => $service->token($row), 'expiry_date' => CarbonImmutable::parse($row->reservation_expires_at, 'UTC')->toISOString(),
                'location_id' => $target['location_id'], 'evse_uid' => $target['evse_uid'], 'authorization_reference' => $row->authorization_reference];
        }

        return $payload;
    }

    private function receiptPayload(object $row, object $claim, string $action, array $expected): bool
    {
        try {
            $payload = json_decode(Crypt::decryptString($claim->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            if ($action === 'TOKEN_REVOKE') {
                // The deactivation retains its original immutable wire clock.
                $expected['last_updated'] = $payload['last_updated'] ?? null;
                if (! is_string($expected['last_updated'])
                    || ! hash_equals($row->token_digest, hash('sha256', $expected['uid']))) {
                    return false;
                }
            }

            return (int) $claim->order_id === (int) $row->id && (int) $claim->actor_id === (int) $row->user_id
                && $claim->action === $action && $payload === $expected
                && hash_equals($claim->intent_hash, hash('sha256', json_encode([$row->id, $action, $payload], JSON_THROW_ON_ERROR)));
        } catch (Throwable) {
            return false;
        }
    }

    private function send(User $actor, object $order, string $action, string $requestKey): void
    {
        $service = app(SandboxRoaming::class);
        $command = DB::transaction(function () use ($actor, $order, $action, $requestKey, $service) {
            app(ChargingActorConsent::class)->capture($actor);
            $p = OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->user_id === $actor->id && $row->intent_kind === 'reservation', 404);
            [,,$local] = $service->current($row, $action === 'RESERVE_NOW');
            $old = DB::table('ocpi_roaming_commands')->where('actor_id', $actor->id)->where('request_key', $requestKey)->first();
            if ($old) {
                abort_unless($old->order_id === $row->id && $old->action === $action, 409);

                return null;
            }
            abort_unless($row->active_target_key !== null, 409, 'This reservation no longer holds capacity.');
            if ($action === 'RESERVE_NOW') {
                $this->policy($p);
                abort_unless($row->state === 'prepared' && ! DB::table('ocpi_roaming_commands')->where('order_id', $row->id)->where('action', 'RESERVE_NOW')->exists(), 409);
            } else {
                abort_if($this->pending($row->id), 409, 'Unknown cancellation outcome requires source reconciliation.');
            }
            $callback = (string) Str::uuid();
            $payload = $this->payload($row, $action, $callback, $local, $service);
            $expected = ['order_id' => $row->id, 'actor_id' => $actor->id, 'request_key' => $requestKey,
                'intent_hash' => hash('sha256', json_encode([$row->id, $action, $payload], JSON_THROW_ON_ERROR)),
                'action' => $action, 'callback_key' => $callback, 'payload_ciphertext' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'state' => 'prepared'];
            $id = DB::table('ocpi_roaming_commands')->insertGetId($expected + ['created_at' => now(), 'updated_at' => now()]);

            return (object) ($expected + ['id' => $id]);
        }, 3);
        if (! $command) {
            return;
        }
        $entered = false;
        try {
            $payload = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($payload) && hash_equals($command->intent_hash, hash('sha256', json_encode([$order->id, $action, $payload], JSON_THROW_ON_ERROR))), 409);
            $p = DB::transaction(function () use ($actor, $order, $command, $service, $action, $payload) {
                app(ChargingActorConsent::class)->capture($actor);
                $p = OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                [, , $local] = $service->current($row, $action === 'RESERVE_NOW');
                abort_unless($row->user_id === $actor->id && $row->active_target_key !== null, 409);
                $expected = $this->payload($row, $action, $command->callback_key, $local, $service);
                if ($action === 'RESERVE_NOW') {
                    $expected['token']['last_updated'] = $payload['token']['last_updated'] ?? null;
                    abort_unless($expected['token']['valid'] === true && is_string($expected['token']['last_updated'])
                        && hash_equals($row->token_digest, hash('sha256', $expected['token']['uid'])), 409);
                }
                abort_unless($payload === $expected, 409, 'Reservation intent changed before dispatch.');
                if ($action === 'RESERVE_NOW') {
                    $this->policy($p);
                    $target = json_decode(Crypt::decryptString($row->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
                    $offer = $service->offer(OcpiRecord::findOrFail($row->location_record_id), $target['evse_uid'], $target['connector_id'], $target['tariff_id']);
                    abort_unless(hash_equals($row->offer_hash, $offer['hash']), 409);
                    abort_unless($this->reservableEvse(OcpiRecord::findOrFail($row->location_record_id), $target['evse_uid'], $target['connector_id']), 409,
                        'Partner EVSE no longer advertises reservation capability.');
                } else {
                    abort_unless($row->reservation_cancelled_at === null, 409);
                    abort_if($this->sessions($row)->contains(fn ($s) => in_array($s->normalized['status'], ['ACTIVE', 'COMPLETED'], true)), 409);
                }
                $claim = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->sameClaim($claim, $command, $row, $action) && $claim->state === 'prepared' && $claim->attempted_at === null && $claim->transport_result === null && $claim->callback_hash === null && $claim->callback_result === null && $claim->callback_at === null, 409);
                DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update(['state' => 'dispatching', 'attempted_at' => now(), 'updated_at' => now()]);
                if (! in_array($row->state, ['source_reserved', 'source_active', 'source_completed'], true)) {
                    DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['state' => $action === 'RESERVE_NOW' ? 'reservation_dispatching' : 'reservation_cancelling', 'updated_at' => now()] + ($action === 'RESERVE_NOW' ? ['token_state' => 'command_preauthorized'] : []));
                }

                return $p;
            }, 3);
            $entered = true;
            $response = app(PartnerClient::class)->write($p, 'commands_receiver', 'POST', [$action], $payload);
            $result = $response['data']['result'] ?? null;
            abort_unless(in_array($result, ['ACCEPTED', 'REJECTED', 'NOT_SUPPORTED', 'UNKNOWN_SESSION'], true), 409);
            DB::transaction(function () use ($order, $command, $result, $action) {
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $claim = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->sameClaim($claim, $command, $row, $action) && $claim->attempted_at !== null, 409);
                if ($claim->transport_result !== null) {
                    abort_unless($claim->transport_result === $result, 409);

                    return;
                }
                $conflicting = $result !== 'ACCEPTED' && ($claim->callback_hash !== null
                    || $claim->callback_result !== null || $claim->callback_at !== null
                    || ($action === 'RESERVE_NOW' && ($row->authorized_at !== null
                        || $row->reservation_cancelled_at !== null
                        || in_array($row->state, ['source_reserved', 'source_active', 'source_completed'], true))));
                $state = $conflicting ? 'outcome_unknown' : ($result === 'ACCEPTED' ? 'transport_accepted' : 'partner_rejected');
                DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update(['state' => $state, 'transport_result' => $result, 'updated_at' => now()]);
                if ($action === 'RESERVE_NOW' && ! in_array($row->state, ['source_reserved', 'source_active', 'source_completed'], true)) {
                    DB::table('ocpi_roaming_orders')->where('id', $row->id)->update([
                        'state' => $conflicting ? 'reservation_outcome_unknown' : ($result === 'ACCEPTED' ? 'reservation_accepted' : 'reservation_rejected'),
                        'token_state' => $row->token_revoked_at !== null ? $row->token_state : ($conflicting ? 'preauthorization_unknown' : ($result === 'ACCEPTED' ? 'command_preauthorized' : 'command_rejected')),
                        'updated_at' => now(),
                    ]);
                }
            }, 3);
        } catch (Throwable) {
            DB::transaction(function () use ($order, $command, $entered, $action) {
                OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
                $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
                $claim = DB::table('ocpi_roaming_commands')->where('id', $command->id)->lockForUpdate()->first();
                $known = $claim !== null && $this->sameClaim($claim, $command, $row, $action);
                $hasEvidence = $claim !== null && ($claim->attempted_at !== null || $claim->transport_result !== null
                    || $claim->callback_hash !== null || $claim->callback_result !== null || $claim->callback_at !== null);
                $uncertain = $entered || ! $known || $hasEvidence
                    || ($claim !== null && $claim->state !== 'prepared')
                    || $row->authorized_at !== null || $row->reservation_cancelled_at !== null;
                if ($claim && (int) $claim->order_id === (int) $row->id && $claim->action === $action
                    && $claim->transport_result === null && $claim->callback_hash === null
                    && $claim->callback_result === null && $claim->callback_at === null
                    && in_array($claim->state, ['prepared', 'dispatching'], true)) {
                    DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update([
                        'state' => $uncertain ? 'outcome_unknown' : 'not_dispatched', 'updated_at' => now(),
                    ]);
                }
                // A failed final claim check must never erase an earlier attempt
                // or release capacity on the assumption that nothing was sent.
                if ($action === 'RESERVE_NOW' && ! in_array($row->state, ['source_reserved', 'source_active', 'source_completed', 'reservation_closed'], true)) {
                    DB::table('ocpi_roaming_orders')->where('id', $row->id)->update([
                        'state' => $uncertain ? 'reservation_outcome_unknown' : 'reservation_not_dispatched',
                        'token_state' => $row->token_revoked_at !== null ? $row->token_state : ($uncertain ? 'preauthorization_unknown' : 'not_sent'),
                        'active_target_key' => $uncertain ? $row->active_target_key : null, 'updated_at' => now(),
                    ]);
                }
            }, 3);
        }
    }

    public function review(object $order): object
    {
        if (($order->intent_kind ?? 'start') !== 'reservation') {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            $service = app(SandboxRoaming::class);
            [, , $local] = $service->current($row);
            if ($row->active_target_key === null || $row->token_revoked_at === null) {
                return $row;
            }
            $cancel = DB::table('ocpi_roaming_commands')->where('order_id', $row->id)
                ->where('actor_id', $row->user_id)->where('action', 'CANCEL_RESERVATION')
                ->whereNotNull('attempted_at')->whereNotNull('callback_hash')->whereNotNull('callback_at')
                ->where('callback_result', 'ACCEPTED')->orderByDesc('callback_at')->orderByDesc('id')->first();
            // A callback must not turn a contradictory synchronous rejection
            // into an independently proven cancellation.
            if ($cancel && ($cancel->transport_result !== 'ACCEPTED' && $cancel->transport_result !== null
                || ! in_array($cancel->state, ['dispatching', 'transport_accepted', 'outcome_unknown'], true)
                || CarbonImmutable::parse($cancel->callback_at, 'UTC')->lt(CarbonImmutable::parse($cancel->attempted_at, 'UTC'))
                || ! $this->receiptPayload($row, $cancel, 'CANCEL_RESERVATION', $this->payload($row, 'CANCEL_RESERVATION', $cancel->callback_key, $local, $service)))) {
                $cancel = null;
            }
            $expired = CarbonImmutable::parse($row->reservation_expires_at, 'UTC')->lte(now());
            if (! $cancel && ! $expired && $row->reservation_cancelled_at === null) {
                return $row;
            }
            $deactivation = DB::table('ocpi_roaming_commands')->where('order_id', $row->id)
                ->where('actor_id', $row->user_id)->where('action', 'TOKEN_REVOKE')
                ->where('state', 'transport_accepted')->whereNotNull('attempted_at')
                ->where('transport_result', 'ACCEPTED')->first();
            if (! $deactivation || $row->token_state !== 'partner_revoked'
                || ! $this->receiptPayload($row, $deactivation, 'TOKEN_REVOKE', $service->token($row, false))
                || CarbonImmutable::parse($deactivation->updated_at, 'UTC')->lt(CarbonImmutable::parse($deactivation->attempted_at, 'UTC'))) {
                return $row;
            }
            $cutoff = CarbonImmutable::parse($cancel?->callback_at ?? $row->reservation_cancelled_at ?? $row->reservation_expires_at, 'UTC');
            // Source availability must follow both the final cancellation
            // evidence and acknowledged token deactivation. Never release
            // capacity using an earlier snapshot while authority still existed.
            foreach ([$row->reservation_cancelled_at, $row->token_revoked_at, $deactivation->updated_at] as $boundary) {
                if ($boundary !== null) {
                    $cutoff = $cutoff->max(CarbonImmutable::parse($boundary, 'UTC'));
                }
            }
            $sessions = $this->sessions($row);
            if ($sessions->count() > 1) {
                return $row;
            }
            if ($sessions->contains(fn ($s) => ! in_array($s->normalized['status'], ['COMPLETED', 'INVALID'], true) || $s->received_at->lte($cutoff))) {
                return $row;
            }
            $location = OcpiRecord::findOrFail($row->location_record_id);
            $target = json_decode(Crypt::decryptString($row->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            if ($location->ocpi_provider_id !== $row->provider_id || $location->module !== 'locations' || $location->country_code !== $target['country_code'] || $location->party_id !== $target['party_id'] || $location->external_id !== $target['location_id'] || ($location->normalized['publish'] ?? false) !== true) {
                return $row;
            }
            $evses = array_values(array_filter($location->normalized['evses'] ?? [], fn ($e) => ($e['uid'] ?? null) === $target['evse_uid']));
            if (! $location->received_at->gt($cutoff) || ! $location->source_updated_at->gt($cutoff) || $location->source_updated_at->gt(now()) || $location->source_updated_at->lt(now()->subMinutes(5)) || ($expired && ! $cancel && $location->source_updated_at->lte(CarbonImmutable::parse($row->reservation_expires_at, 'UTC'))) || hash_equals($target['location_hash'], $location->content_hash) || count($evses) !== 1 || ($evses[0]['reported_status'] ?? null) !== 'AVAILABLE' || ! $this->hasPostClosureConnectorEvidence($location, $target, $cutoff)) {
                return $row;
            }
            DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['state' => 'reservation_closed', 'active_target_key' => null, 'reservation_closed_at' => $location->received_at, 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $row->user_id, 'action' => 'ocpi.reservation_source_closure_observed', 'subject' => (string) $row->id, 'created_at' => now()]);

            return DB::table('ocpi_roaming_orders')->find($row->id);
        }, 3);
    }
}
