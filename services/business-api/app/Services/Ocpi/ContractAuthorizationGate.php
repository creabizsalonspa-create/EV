<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Opt-in administrative authorization gate, not a runtime protocol gate. */
final class ContractAuthorizationGate
{
    public static function required(): bool
    {
        return config('ocpi.contract_review_authorization_required') === true;
    }

    /** Called with owner and provider locked by the administrative caller. */
    public static function permits(OcpiProvider $provider, User $owner): bool
    {
        if (! self::required()) {
            return true;
        }
        $row = DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->orderByDesc('revision')->first();

        return self::validReview($row, $provider, $owner, CarbonImmutable::now('UTC'));
    }

    /** In-memory evidence fingerprint for new negotiation only. A policy
     * toggle or replacement review retires an incomplete negotiation. */
    public static function negotiationAuthority(OcpiProvider $provider): ?string
    {
        if (! self::required()) {
            return null;
        }
        $owner = User::find($provider->owner_id);
        $row = DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->orderByDesc('revision')->first();
        if (! $owner || ! self::validReview($row, $provider, $owner, CarbonImmutable::now('UTC'))) {
            throw new OcpiException('A current internal contract review is required for negotiation.');
        }

        return hash('sha256', json_encode([
            $provider->id, $provider->owner_id, $provider->connection_key,
            $row->revision, $row->reviewer_id, $row->reference_id,
            $row->evidence_sha256, $row->valid_until, $row->payload_hash,
        ], JSON_THROW_ON_ERROR));
    }

    /** Capture the same evidence for credential establishment/rotation.
     * Unregister is cleanup and intentionally retains existing authority. */
    public static function credentialAuthority(OcpiProvider $provider, string $action): ?string
    {
        if ($action === 'unregister') {
            return null;
        }
        try {
            return self::negotiationAuthority($provider);
        } catch (OcpiException) {
            abort(409, 'A current internal contract review is required for credential establishment or rotation.');
        }
    }

    public static function assertCredentialAuthority(OcpiProvider $provider, string $action, ?string $expected): void
    {
        abort_unless(self::credentialAuthority($provider, $action) === $expected, 409,
            'Internal contract evidence changed; credential exchange was fenced.');
    }

    public static function validReview(?object $row, OcpiProvider $provider, User $owner, CarbonImmutable $at): bool
    {
        if (! $row || (int) $provider->owner_id !== (int) $owner->id
            || (int) $row->owner_id !== (int) $owner->id
            || $owner->role !== 'vendor' || $owner->account_status !== 'active'
            || $row->connection_key !== $provider->connection_key || $row->decision !== 'review'
            || ! is_string($row->reference_id) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{5,119}$/D', $row->reference_id)
            || ! is_string($row->evidence_sha256) || ! preg_match('/^[a-f0-9]{64}$/D', $row->evidence_sha256)) {
            return false;
        }
        $date = $row->valid_until;

        return is_string($date)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && $date >= $at->toDateString()
            && $date <= $at->addYears(3)->toDateString();
    }
}
