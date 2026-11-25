<?php

namespace App\Services\Ocpi;

/**
 * A review expiry stops new grants, never the explicitly customer-bound
 * stop, cancel or token deactivation path. All existing peer, generation,
 * order ownership, source and claim checks remain mandatory.
 */
final class RoamingReviewPolicy
{
    public static function cleanupCommand(mixed $action): bool
    {
        return in_array($action, ['STOP_SESSION', 'CANCEL_RESERVATION'], true);
    }

    public static function decommissioning(string $module, string $method, array $segments, array $body): bool
    {
        if ($module === 'commands_receiver' && $method === 'POST' && array_is_list($segments)
            && count($segments) === 1 && self::cleanupCommand($segments[0])) {
            $target = $segments[0] === 'STOP_SESSION' ? 'session_id' : 'reservation_id';

            return count($body) === 2 && is_string($body[$target] ?? null) && $body[$target] !== ''
                && is_string($body['response_url'] ?? null) && $body['response_url'] !== '';
        }

        return $module === 'tokens_receiver' && $method === 'PUT' && array_is_list($segments)
            && count($segments) === 3 && ($body['valid'] ?? null) === false
            && ($body['whitelist'] ?? null) === 'NEVER'
            && $segments === [$body['country_code'] ?? null, $body['party_id'] ?? null, $body['uid'] ?? null]
            && is_string($segments[0]) && is_string($segments[1]) && is_string($segments[2]);
    }
}
