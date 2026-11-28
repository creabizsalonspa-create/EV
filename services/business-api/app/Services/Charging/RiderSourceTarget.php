<?php

namespace App\Services\Charging;

/** Typed source projections for a single explicitly scoped connector. */
final class RiderSourceTarget
{
    public static function stationId(mixed $rows, string $protocol): ?int
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])) {
            return null;
        }
        $row = $rows[0];
        $id = $row['id'] ?? null;

        return is_int($id) && $id > 0 && $id <= 2147483647
            && ($row['isOnline'] ?? null) === true
            && in_array($protocol, ['1.6', '2.0.1'], true)
            && in_array($row['protocol'] ?? null, [$protocol, 'ocpp'.$protocol], true) ? $id : null;
    }

    public static function receipt(mixed $connectors, mixed $statuses, int $connector, ?int $sourceEvse, int $wireEvse, int $wireConnector, string $protocol, bool $existingRemoteSession): ?array
    {
        if ($connector < 1 || $connector > 2147483647 || $wireConnector < 1
            || ! in_array($protocol, ['1.6', '2.0.1'], true)
            || ($protocol === '1.6' ? $wireEvse !== 0 : $wireEvse < 1)
            || ! is_array($connectors) || ! array_is_list($connectors) || count($connectors) !== 1 || ! is_array($connectors[0])
            || ! is_array($statuses) || ! array_is_list($statuses) || count($statuses) !== 1 || ! is_array($statuses[0])) {
            return null;
        }
        $row = $connectors[0];
        $status = $statuses[0];
        $evseId = $row['evseId'] ?? null;
        if (($row['id'] ?? null) !== $connector || ! is_int($evseId) || $evseId < 1 || $evseId > 2147483647
            || ($sourceEvse !== null && $sourceEvse !== $evseId)
            || ($status['evseId'] ?? ($protocol === '1.6' ? 0 : null)) !== $wireEvse
            || ($status['connectorId'] ?? null) !== $wireConnector
            || ! is_string($status['timestamp'] ?? null)) {
            return null;
        }
        $states = match ($protocol) {
            '1.6' => $existingRemoteSession ? ['Available', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved'] : ['Available'],
            '2.0.1' => $existingRemoteSession ? ['Available', 'Occupied', 'Reserved'] : ['Available'],
        };
        $error = $status['errorCode'] ?? null;

        return in_array($status['connectorStatus'] ?? null, $states, true)
            && ($protocol === '1.6' ? $error === 'NoError' : in_array($error, [null, 'NoError'], true)) ? $status : null;
    }
}
