<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A manual document-digest review is an opt-in, internal software gate only.
 * It does NOT verify legal execution, external OCPI partner acceptance, real
 * charging rights, any hardware or a payment relationship.
 */
final class ContractReviewGate
{
    public function current(OcpiProvider $provider, ?User $owner = null): bool
    {
        if (config('ocpi.contract_review_required') !== true) {
            return true;
        }
        $owner ??= User::whereKey($provider->owner_id)->first();
        if (! $owner || (int) $owner->id !== (int) $provider->owner_id
            || $owner->role !== 'vendor' || $owner->account_status !== 'active') {
            return false;
        }
        $latest = DB::table('ocpi_partner_contract_reviews')
            ->where('provider_id', $provider->id)->orderByDesc('revision')->first();

        return $latest && (int) $latest->owner_id === (int) $owner->id
            && $latest->decision === 'review'
            && is_string($latest->valid_until)
            && $latest->valid_until >= now('UTC')->toDateString();
    }
}
