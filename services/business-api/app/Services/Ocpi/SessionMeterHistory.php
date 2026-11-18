<?php

namespace App\Services\Ocpi;

use App\Models\OcpiRecord;
use App\Services\Charging\SourceConsumer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Accepted source observations, not delivered energy, invoices or a parallel session ledger. */
final class SessionMeterHistory
{
    public function lineage(array $raw, array $n): string
    {
        $token = is_array($raw['cdr_token'] ?? null) ? $raw['cdr_token'] : [];
        $leaf = static fn ($v) => is_string($v) && strlen($v) <= 64 ? $v : null;
        $identity = [];
        foreach (['country_code', 'party_id', 'id', 'location_id', 'evse_uid', 'connector_id'] as $key) {
            $identity[] = $leaf($n[$key] ?? null);
        }
        $identity[] = $leaf($raw['authorization_reference'] ?? null);
        foreach (['uid', 'type', 'country_code', 'party_id', 'contract_id'] as $key) {
            $identity[] = $leaf($token[$key] ?? null);
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /** Called inside the canonical ingestion transaction under the provider lock. No backfill. */
    public function append(OcpiRecord $record, int $owner, ?OcpiRecord $previous = null): void
    {
        $n = $record->normalized;
        $lineage = $this->lineage($record->payload, $n);
        [$safe, $wh] = $this->reading($n['energy_kwh'] ?? null);
        $prior = DB::table('ocpi_session_meter_history')->where('record_id', $record->id)->where('lineage_hash', $lineage)->max('energy_wh');
        if ($previous && hash_equals($lineage, $this->lineage($previous->payload, $previous->normalized))) {
            [, $baseline] = $this->reading($previous->normalized['energy_kwh'] ?? null);
            $prior = $baseline === null ? $prior : max((int) ($prior ?? 0), $baseline);
        }
        DB::table('ocpi_session_meter_history')->insert([
            'record_id' => $record->id, 'provider_owner_id' => $owner, 'content_hash' => $record->content_hash, 'lineage_hash' => $lineage,
            'status' => $n['status'], 'reported_energy_kwh' => $safe, 'energy_wh' => $wh,
            'meter_regressed' => $wh !== null && $prior !== null && $wh < (int) $prior,
            'precision_requires_review' => $wh === null, 'source_clock_ahead' => $record->source_updated_at->gt($record->received_at),
            'source_updated_at' => $record->source_updated_at->format('Y-m-d H:i:s.u'), 'received_at' => $record->received_at->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function reading(mixed $energy): array
    {
        $safe = is_string($energy) && preg_match('/^(0|[1-9][0-9]{0,6})(?:\.[0-9]{1,9})?$/D', $energy) ? $energy : null;
        $wh = null;
        try {
            if ($safe !== null) {
                $wh = SourceConsumer::wh($safe, 'kWh');
            }
        } catch (\Throwable) {
        }

        return [$safe, $wh];
    }

    public function requiresReview(OcpiRecord $session): bool
    {
        $query = DB::table('ocpi_session_meter_history')->where('record_id', $session->id)
            ->where('lineage_hash', $this->lineage($session->payload, $session->normalized));

        return (clone $query)->where('meter_regressed', true)->exists()
            || $query->where('meter_regressed', false)->where('precision_requires_review', true)->exists();
    }

    public function orderLineage(object $order): ?string
    {
        if (! $order->source_session_record_id) {
            return null;
        }
        $source = OcpiRecord::whereKey($order->source_session_record_id)->where('module', 'sessions')->where('ocpi_provider_id', $order->provider_id)->first();
        $command = DB::table('ocpi_roaming_commands')->where('order_id', $order->id)->where('action', ($order->intent_kind ?? 'start') === 'reservation' ? 'RESERVE_NOW' : 'START_SESSION')->first();
        if (! $source || ! $command || $command->attempted_at === null) {
            return null;
        }
        $payload = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($command->intent_hash, hash('sha256', json_encode([$order->id, $command->action, $payload], JSON_THROW_ON_ERROR))), 409);
        $target = json_decode(Crypt::decryptString($order->target_ciphertext), true, flags: JSON_THROW_ON_ERROR);

        return $this->lineage(['cdr_token' => $payload['token'], 'authorization_reference' => $order->authorization_reference], [
            'country_code' => $target['country_code'], 'party_id' => $target['party_id'], 'id' => $source->external_id,
            'location_id' => $target['location_id'], 'evse_uid' => $target['evse_uid'], 'connector_id' => $target['connector_id'],
        ]);
    }
}
