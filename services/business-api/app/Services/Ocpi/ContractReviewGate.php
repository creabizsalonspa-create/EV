<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A manual document-digest review is an opt-in, internal software gate only.
 * It does NOT verify legal execution, external OCPI partner acceptance, real
 * charging rights, any hardware or a payment relationship.
 */
final class ContractReviewGate
{
    /**
     * Gate source-labelled public discovery at SQL selection time, before
     * pagination and before hydration. An old owner or revoked latest review
     * never makes a partner station public through an earlier approved row.
     *
     * No change to the public catalog unless explicitly enabled by operator.
     * This never certifies physical freshness or the partner's legal rights.
     */
    public function eligiblePublic(Builder $providers): Builder
    {
        if (config('ocpi.contract_review_required') !== true) {
            return $providers;
        }

        return $providers
            ->whereIn('ocpi_providers.owner_id', User::query()
                ->where('role', 'vendor')->where('account_status', 'active')->select('id'))
            ->whereExists(function ($evidence) {
                $evidence->selectRaw('1')->from('ocpi_partner_contract_reviews as review')
                    ->whereColumn('review.provider_id', 'ocpi_providers.id')
                    ->whereColumn('review.owner_id', 'ocpi_providers.owner_id')
                    ->where('review.decision', 'review')
                    ->where('review.valid_until', '>=', now('UTC')->toDateString())
                    ->whereNotExists(function ($later) {
                        $later->selectRaw('1')->from('ocpi_partner_contract_reviews as newer')
                            ->whereColumn('newer.provider_id', 'review.provider_id')
                            ->whereColumn('newer.revision', '>', 'review.revision');
                    });
            });
    }

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
