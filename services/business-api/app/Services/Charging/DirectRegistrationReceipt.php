<?php

namespace App\Services\Charging;

/**
 * Registration historically hashes the seven selected fields in response order.
 * JSON object order is not a source identity property. Only an exact digest
 * of the known typed offline registration fields is eligible for reconciliation.
 */
final class DirectRegistrationReceipt
{
    public static function matches(string $digest, int $station, string $identity, string $protocol, string $manufacturer, string $model): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $digest) || $station < 1
            || ! in_array($protocol, ['1.6', '2.0.1'], true)) {
            return false;
        }
        $fields = [
            'id' => $station, 'tenantId' => 1,
            'ocppConnectionName' => $identity, 'protocol' => 'ocpp'.$protocol,
            'isOnline' => false, 'chargePointVendor' => $manufacturer,
            'chargePointModel' => $model,
        ];
        if (hash_equals($digest, hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)))) {
            return true;
        }

        // Legacy responses may serialize keys in another order. Seven fields
        // bound the operator-only check to at most 7! digest candidates.
        return self::matchesOrder($digest, [], $fields);
    }

    private static function matchesOrder(string $digest, array $prefix, array $remaining): bool
    {
        if ($remaining === []) {
            return hash_equals($digest, hash('sha256', json_encode($prefix, JSON_THROW_ON_ERROR)));
        }
        foreach ($remaining as $key => $value) {
            $rest = $remaining;
            unset($rest[$key]);
            if (self::matchesOrder($digest, $prefix + [$key => $value], $rest)) {
                return true;
            }
        }

        return false;
    }
}
