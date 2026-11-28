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
