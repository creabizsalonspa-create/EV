<?php

namespace App\Services\Charging;

use DateTimeInterface;

/** Pure review coverage check. A matching review is not a charging mapping. */
final class DirectConnectorReviewCoverage
{
    /** Pin reviewed local inventory attributes, excluding mutable maintenance status. */
    public static function connectorFingerprint(object $connector, int $generation): string
    {
        return hash('sha256', json_encode([
            (int) $connector->id, (int) $connector->charger_id,
            (int) $connector->connector_number, $connector->connector_type,
            (string) $connector->max_power_kw, $generation,
        ], JSON_THROW_ON_ERROR));
    }

    public function evaluate(array $inventory, array $receipts, array $sources, int $registration, int $epoch, string $fingerprint, bool $complete): array
    {
        $result = [
            'evidence' => 'DIRECT_CONNECTOR_REVIEW_COVERAGE_ONLY',
            'state' => 'requires_review',
            'inventory_count' => count($inventory),
            'source_target_count' => count($sources),
            'current_review_count' => 0,
            'charging_mapping_created' => false,
            'physical_availability_verified' => false,
            'remote_control_authorized' => false,
        ];
        if (! $complete || count($inventory) < 1 || count($inventory) > 100
            || count($sources) !== count($inventory) || count($receipts) > count($inventory)) {
            return $result;
        }
        $owned = [];
        foreach ($inventory as $connector) {
            $id = $connector['id'] ?? null;
            $localFingerprint = $connector['fingerprint'] ?? null;
            if (! is_int($id) || $id < 1 || isset($owned[$id])
                || ! is_string($localFingerprint) || ! preg_match('/^[a-f0-9]{64}$/D', $localFingerprint)) {
                return $result;
            }
            $owned[$id] = $localFingerprint;
        }
        $byTarget = [];
        $sourceIds = [];
        foreach ($sources as $source) {
            $target = $source['target'];
            $id = $source['database_id'];
            if (! is_string($target) || ! is_int($id) || $id < 1
                || isset($byTarget[$target]) || isset($sourceIds[$id])) {
                return $result;
            }
            $byTarget[$target] = $source;
            $sourceIds[$id] = true;
        }
        $seen = [];
        $matched = [];
        foreach ($receipts as $receipt) {
            $id = $receipt['connector_id'];
            $target = $receipt['target'];
            $source = $byTarget[$target] ?? null;
            if (! is_int($id) || ! isset($owned[$id]) || isset($seen[$id]) || isset($matched[$target])
                || $receipt['registration_id'] !== $registration || $receipt['authority_epoch'] !== $epoch
                || ! is_string($receipt['fingerprint']) || ! hash_equals($fingerprint, $receipt['fingerprint'])
                || ! is_string($receipt['connector_fingerprint'] ?? null)
                || ! hash_equals($owned[$id], $receipt['connector_fingerprint'])
                || ! $source || $source['database_id'] !== $receipt['database_id']
                || $source['state'] !== 'recent' || ! is_int($source['event_id']) || ! is_int($receipt['event_id'])
                || $receipt['event_id'] < 1 || $source['event_id'] < $receipt['event_id']
                || ! ($source['observed_at'] instanceof DateTimeInterface)
                || ! ($receipt['observed_at'] instanceof DateTimeInterface)
                || $source['observed_at'] < $receipt['observed_at']) {
                return $result;
            }
            $seen[$id] = true;
            $matched[$target] = true;
        }
        $result['current_review_count'] = count($seen);
        if (count($seen) === count($owned) && count($matched) === count($byTarget)) {
            $result['state'] = 'all_identities_reviewed';
        }

        return $result;
    }
}
