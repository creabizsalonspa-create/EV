<?php

namespace App\Services\Charging;

/** Epoch rotation may change authority, not the original registration evidence. */
final class DirectRegistrationHistory
{
    public static function fingerprint(object $row, array $authority): string
    {
        return hash('sha256', json_encode([
            $row->id, $row->charger_id, $row->binding_id, $row->profile_id,
            $row->source_station_id, $row->source_generation, $row->source_receipt_hash,
            $row->verified_at, $row->payload_hash, $authority,
        ], JSON_THROW_ON_ERROR));
    }

    public static function matches(object $row, object $history, int $currentEpoch): bool
    {
        return is_int($history->epoch) && $history->epoch > 0
            && $history->epoch <= $currentEpoch
            && is_string($history->authority_fingerprint)
            && preg_match('/^[a-f0-9]{64}$/D', $history->authority_fingerprint)
            && is_string($history->registration_fingerprint)
            && hash_equals($history->registration_fingerprint,
                self::fingerprint($row, [$history->epoch, $history->authority_fingerprint]));
    }
}
