<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Charging\SourceConsumer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Customer/source lineage only. No reservation, invoice, collection or settlement writes. */
class RoamingReconciler
{
    /** Persisted database instants must not accept relative dates or rolled-over calendar values. */
    private static function storedInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T]([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.(\d{1,6}))?Z?$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function review(object $row): object
    {
        DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['reconciliation_state' => 'requires_review', 'updated_at' => now()]);

        return DB::table('ocpi_roaming_orders')->find($row->id);
    }

    /** Reconcile actual source facts even after a contradictory transport reply,
     * but never treat an unattempted or rebound command as customer authority. */
    private function attemptedIntent(object $row, array $target, array $local, string $uid): ?object
    {
        $action = ($row->intent_kind ?? 'start') === 'reservation' ? 'RESERVE_NOW' : 'START_SESSION';
        $claims = DB::table('ocpi_roaming_commands')->where('order_id', $row->id)->where('action', $action)->limit(2)->lockForUpdate()->get();
        if ($claims->count() !== 1) {
            return null;
        }
        $claim = $claims->first();
        try {
            $payload = json_decode(Crypt::decryptString($claim->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $token = $payload['token'] ?? [];
            $issuer = $local['roles'][0];
            if ((int) $claim->actor_id !== (int) $row->user_id || $claim->request_key !== $row->request_key
                || $claim->attempted_at === null || ! in_array($claim->state, ['dispatching', 'transport_accepted', 'outcome_unknown', 'partner_rejected'], true)
                || ! hash_equals($claim->intent_hash, hash('sha256', json_encode([$row->id, $action, $payload], JSON_THROW_ON_ERROR)))
                || ! hash_equals($row->token_digest, hash('sha256', $uid))
                || ($token['uid'] ?? null) !== $uid || ($token['type'] ?? null) !== 'APP_USER'
                || ($token['country_code'] ?? null) !== $issuer['country_code'] || ($token['party_id'] ?? null) !== $issuer['party_id']
                || ($token['contract_id'] ?? null) !== $row->authorization_reference || ($token['valid'] ?? null) !== true
                || ($payload['response_url'] ?? null) !== substr($local['url'], 0, -strlen('versions')).'2.2.1/commands/'.$claim->callback_key
                || ($payload['authorization_reference'] ?? null) !== $row->authorization_reference
                || ($payload['location_id'] ?? null) !== $target['location_id'] || ($payload['evse_uid'] ?? null) !== $target['evse_uid']
                || ($action === 'START_SESSION' && ($payload['connector_id'] ?? null) !== $target['connector_id'])
                || ($action === 'RESERVE_NOW' && (($payload['reservation_id'] ?? null) !== $row->reservation_id
                    || ($payload['expiry_date'] ?? null) !== CarbonImmutable::parse($row->reservation_expires_at, 'UTC')->toISOString()))) {
                return null;
            }

            return $claim;
        } catch (Throwable) {
            return null;
        }
    }

    public function reconcile(object $order): object
    {
        return DB::transaction(function () use ($order) {
            $p = OcpiProvider::whereKey($order->provider_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('ocpi_roaming_orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            app(SandboxRoaming::class)->current($row);
            [, $local] = app(SandboxRoaming::class)->policy($p);
            $issuer = $local['roles'][0];
            $target = json_decode(Crypt::decryptString($row->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $uid = Crypt::decryptString($row->token_ciphertext);
            $records = OcpiRecord::where('ocpi_provider_id', $p->id)->where('module', 'sessions')->where('country_code', $target['country_code'])->where('party_id', $target['party_id'])->where('id', '>', $row->source_record_floor)->orderByDesc('id')->limit(1001)->get();
            abort_if($records->count() > 1000, 409, 'Partner history needs bounded manual review.');
            $attempt = $this->attemptedIntent($row, $target, $local, $uid);
            $attemptedAt = self::storedInstant($attempt?->attempted_at);
            if ($attemptedAt === null && ($records->isNotEmpty() || $row->source_session_record_id !== null)) {
                return $this->review($row);
            }
            try {
                $matches = $records->filter(fn ($r) => app(SandboxRoaming::class)->ownsSourceSession($row, $r));
            } catch (Throwable) {
                return $this->review($row);
            }
            if ($matches->count() !== 1 || $attemptedAt === null) {
                if ($matches->count() > 1 || ($matches->isEmpty() && $row->source_session_record_id !== null)) {
                    DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['reconciliation_state' => 'requires_review', 'updated_at' => now()]);
                }

                return DB::table('ocpi_roaming_orders')->find($row->id);
            }
            $session = $matches->first();
            $integrity = app(StoredSourceIntegrity::class);
            $sessionReceived = self::storedInstant($session->getRawOriginal('received_at'));
            if (! $integrity->consistent($session) || $sessionReceived === null || $sessionReceived->lt($attemptedAt)) {
                return $this->review($row);
            }
            $n = $session->normalized;
            // A valid digest does not make a backward lifecycle transition
            // valid. Preserve the last observed active/terminal facts.
            if ($row->source_status === 'ACTIVE' && in_array($n['status'], ['PENDING', 'RESERVATION'], true)) {
                return $this->review($row);
            }
            if ($row->source_status === 'COMPLETED' && $row->source_session_hash !== null && ! hash_equals($row->source_session_hash, $session->content_hash)) {
                DB::table('ocpi_roaming_orders')->where('id', $row->id)->update(['reconciliation_state' => 'requires_review', 'updated_at' => now()]);

                return DB::table('ocpi_roaming_orders')->find($row->id);
            }
            abort_unless($row->source_session_record_id === null || $row->source_session_record_id === $session->id, 409, 'Session identity requires review.');
            $review = $n['currency'] !== $target['tariff']['currency'] || ! CarbonImmutable::parse($n['start_date_time'])->betweenIncluded(CarbonImmutable::parse($row->created_at)->subSeconds(2), CarbonImmutable::parse($row->expires_at));
            try {
                $wh = SourceConsumer::wh($n['energy_kwh'], 'kWh');
                if ($wh < $row->energy_wh || $wh > 1000000) {
                    $review = true;
                    $wh = $row->energy_wh;
                }
            } catch (Throwable) {
                $review = true;
                $wh = $row->energy_wh;
            }
            $review = $review || app(SessionMeterHistory::class)->requiresReview($session);
            // Price/meter discrepancies must not hide a proven owned active session from stop.

            abort_unless(in_array($n['status'], ['PENDING', 'ACTIVE', 'COMPLETED', 'INVALID', 'RESERVATION'], true), 409, 'Unsupported partner session transition.');
            abort_if($row->source_status === 'COMPLETED' && $n['status'] !== 'COMPLETED', 409, 'Completed source history is immutable.');
            $values = ['source_session_record_id' => $session->id, 'source_session_hash' => $session->content_hash, 'energy_wh' => $wh, 'source_status' => $n['status'], 'source_observed_at' => now(), 'reconciliation_state' => 'awaiting_cdr', 'updated_at' => now()];
            if ($n['status'] === 'RESERVATION') {
                abort_unless(($row->intent_kind ?? 'start') === 'reservation', 409);
                $values['state'] = 'source_reserved';
            }
            if ($n['status'] === 'ACTIVE') {
                $values['state'] = 'source_active';
            }
            if ($n['status'] === 'COMPLETED') {
                $values['state'] = 'source_completed';
                $values['active_target_key'] = null;
                if (($row->intent_kind ?? 'start') === 'reservation' && $row->reservation_closed_at === null) {
                    $values['reservation_closed_at'] = $sessionReceived;
                }
            }
            if ($n['status'] === 'INVALID') {
                $values['reconciliation_state'] = 'requires_review';
            }
            $cdrs = OcpiRecord::where('ocpi_provider_id', $p->id)->where('module', 'cdrs')->where('country_code', $target['country_code'])->where('party_id', $target['party_id'])->where('id', '>', $row->source_record_floor)->orderByDesc('id')->limit(1001)->get();
            abort_if($cdrs->count() > 1000, 409, 'Partner CDR history needs bounded manual review.');
            try {
                $claims = $cdrs->filter(function ($r) use ($row, $session, $uid, $issuer) {
                    $raw = $r->payload;

                    return ($raw['session_id'] ?? null) === $session->external_id && ($raw['authorization_reference'] ?? null) === $row->authorization_reference && ($raw['cdr_token']['uid'] ?? null) === $uid && ($raw['cdr_token']['type'] ?? null) === 'APP_USER' && ($raw['cdr_token']['country_code'] ?? null) === $issuer['country_code'] && ($raw['cdr_token']['party_id'] ?? null) === $issuer['party_id'] && ($raw['cdr_token']['contract_id'] ?? null) === $row->authorization_reference;
                });
            } catch (Throwable) {
                $claims = collect();
                $review = true;
            }
            if ($claims->count() > 1) {
                $values['reconciliation_state'] = 'requires_review';
            } elseif ($claims->count() === 1) {
                $cdr = $claims->first();
                $cdrReceived = self::storedInstant($cdr->getRawOriginal('received_at'));
                if (! $integrity->consistent($cdr) || $cdrReceived === null || $cdrReceived->lt($attemptedAt)) {
                    $review = true;
                } else {
                    $comparison = app(CdrReconciler::class)->inspect($p, $cdr);
                    $values['reconciliation_state'] = $comparison['status'];
                    $values['source_cdr_record_id'] = $cdr->id;
                    $values['source_cdr_hash'] = $cdr->content_hash;
                }
            }
            if ($review) {
                $values['reconciliation_state'] = 'requires_review';
            }
            DB::table('ocpi_roaming_orders')->where('id', $row->id)->update($values);

            return DB::table('ocpi_roaming_orders')->find($row->id);
        }, 3);
    }
}
