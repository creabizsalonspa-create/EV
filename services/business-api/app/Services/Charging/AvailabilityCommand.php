<?php

namespace App\Services\Charging;

use InvalidArgumentException;

/** Builds protocol data from canonical source addresses, never from client-supplied addresses. */
final class AvailabilityCommand
{
    public static function payload(string $protocol, string $state, ?int $evse = null, ?int $connector = null): array
    {
        if (! in_array($state, ['Operative', 'Inoperative'], true)) {
            throw new InvalidArgumentException('Unsupported availability state.');
        }

        if ($protocol === '1.6') {
            if ($evse !== null || ($connector !== null && $connector < 1)) {
                throw new InvalidArgumentException('OCPP 1.6 requires a connector address or station scope.');
            }

            return ['connectorId' => $connector ?? 0, 'type' => $state];
        }

        if ($protocol !== '2.0.1' || ($evse !== null && $evse < 1)
            || ($connector !== null && ($evse === null || $connector < 1))) {
            throw new InvalidArgumentException('Unsupported OCPP availability target.');
        }

        $payload = ['operationalStatus' => $state];
        if ($evse !== null) {
            $payload['evse'] = ['id' => $evse];
            if ($connector !== null) {
                $payload['evse']['connectorId'] = $connector;
            }
        }

        return $payload;
    }

    /** A correlated protocol confirmation is distinct from an HTTP submission receipt. */
    public static function confirmation(mixed $status): string
    {
        return match ($status) {
            'Accepted' => 'accepted',
            'Rejected' => 'rejected',
            'Scheduled' => 'scheduled',
            default => 'outcome_unknown',
        };
    }
}
