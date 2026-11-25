<?php

namespace App\Services\Ocpi;

use DateTimeInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** An unconsumed prepared approval must agree with the current bootstrap link. */
final class EnrollmentProof
{
    public static function matches(object $link, string $proof, mixed $digest, DateTimeInterface $checkedAt): bool
    {
        if (! is_string($digest) || ! hash_equals($digest, hash('sha256', $proof))
            || ! is_string($link->configuration_hash ?? null)) {
            return false;
        }
        try {
            $evidence = json_decode($proof, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($evidence)
            && ($evidence['configuration_hash'] ?? null) === $link->configuration_hash
            && EnrollmentExpiry::future($evidence['expires_at'] ?? null, $checkedAt)
            && EnrollmentExpiry::same($evidence['expires_at'] ?? null, $link->bootstrap_expires_at ?? null);
    }

    public static function current(object $link, bool $lock = false): ?object
    {
        $checkedAt = now('UTC');
        if (($link->state ?? null) !== 'awaiting_registration'
            || ! EnrollmentExpiry::future($link->bootstrap_expires_at ?? null, $checkedAt)) {
            return null;
        }
        $query = DB::table('ocpi_credential_operations')->where('provider_id', $link->provider_id)
            ->where('generation', $link->generation)->where('action', 'prepare_incoming')
            ->where('state', 'awaiting_peer')->whereNull('completed_at')->limit(2);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        if ($rows->count() !== 1) {
            return null;
        }
        $row = $rows->first();
        try {
            $proof = Crypt::decryptString($row->source_payload_ciphertext);
        } catch (Throwable) {
            return null;
        }

        return self::matches($link, $proof, $row->source_payload_hash, $checkedAt) ? $row : null;
    }
}
