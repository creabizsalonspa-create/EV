<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A manual document-digest review is an opt-in, internal software gate only.
 * It does NOT verify legal execution, external OCPI partner acceptance, real
 * charging rights, any hardware or a payment relationship.
 */
final class ContractReviewGate
{
    /** A stored review date must be canonical and inside the approval policy. */
    public static function validUntil(mixed $value, CarbonInterface $checkedAt): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && $value >= $checkedAt->toDateString()
            && $value <= $checkedAt->copy()->addYears(3)->toDateString();
    }

    /** Stored manual review evidence must retain the accepted document identity. */
    public static function validEvidence(mixed $reference, mixed $digest): bool
    {
        return is_string($reference)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{5,119}$/D', $reference) === 1
            && is_string($digest)
            && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }

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
        // PR #232 active-vendor visibility always applies, even when
        // the optional internal contract-review gate is disabled.
        $providers->publiclyDiscoverable();
        if (config('ocpi.contract_review_required') !== true) {
            return $providers;
        }

        $checkedAt = now('UTC');
        $sqlite = $providers->getModel()->getConnection()->getDriverName() === 'sqlite';

        return $providers->whereExists(function ($evidence) use ($checkedAt, $sqlite) {
            $evidence->selectRaw('1')->from('ocpi_partner_contract_reviews as review')
                ->whereColumn('review.provider_id', 'ocpi_providers.id')
                ->whereColumn('review.owner_id', 'ocpi_providers.owner_id')
                ->whereColumn('review.connection_key', 'ocpi_providers.connection_key')
                ->where('review.decision', 'review')
                ->when($sqlite, function ($query) {
                    $query->whereRaw("length(review.reference_id) between 6 and 120")
                        ->whereRaw("review.reference_id GLOB '[A-Za-z0-9]*'")
                        ->whereRaw("review.reference_id NOT GLOB '*[^A-Za-z0-9_.-]*'")
                        ->whereRaw("instr(review.reference_id, char(0)) = 0")
                        ->whereRaw("length(review.evidence_sha256) = 64")
                        ->whereRaw("review.evidence_sha256 NOT GLOB '*[^a-f0-9]*'")
                        ->whereRaw("instr(review.evidence_sha256, char(0)) = 0");
                }, function ($query) {
                    $query->whereRaw('review.reference_id ~ ?', ['^[A-Za-z0-9][A-Za-z0-9_.-]{5,119}$'])
                        ->whereRaw('review.evidence_sha256 ~ ?', ['^[a-f0-9]{64}$']);
                })
                ->whereBetween('review.valid_until', [$checkedAt->toDateString(), $checkedAt->copy()->addYears(3)->toDateString()])
                // SQLite DATE affinity permits malformed calendar strings.
                // PostgreSQL's typed date column already enforces calendar validity.
                ->when($sqlite, fn ($query) => $query->whereRaw("date(review.valid_until, '+0 days') = review.valid_until"))
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
            && $latest->connection_key === $provider->connection_key
            && $latest->decision === 'review'
            && self::validEvidence($latest->reference_id, $latest->evidence_sha256)
            && self::validUntil($latest->valid_until, now('UTC'));
    }
}
