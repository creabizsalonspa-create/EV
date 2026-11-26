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

    /** A reviewed provider identity may never be recycled for a different counterparty. */
    public function sourceBindingAllows(OcpiProvider $provider, ?string $identity): bool
    {
        $bound = DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->whereNotNull('counterparty_hash');

        return $identity === null ? ! $bound->exists()
            : ! $bound->where('counterparty_hash', '!=', $identity)->exists();
    }

    /** First review cannot retrospectively attest an existing, unbound source archive. */
    public function canReviewSource(OcpiProvider $provider, string $identity): bool
    {
        if (! $this->sourceBindingAllows($provider, $identity)) {
            return false;
        }
        $bound = DB::table('ocpi_partner_contract_reviews')->where('provider_id', $provider->id)
            ->where('counterparty_hash', $identity)->exists();

        return $bound || ! DB::table('ocpi_records')->where('ocpi_provider_id', $provider->id)->exists();
    }

    /**
     * Gate source-labelled public discovery at SQL selection time, before
     * pagination and before hydration. An old owner or revoked latest review
     * never makes a partner station public through an earlier approved row.
     *
     * Unbound legacy sources retain policy-off behavior; reviewed identity never changes.
     * This never certifies physical freshness or the partner's legal rights.
     */
    public function eligiblePublic(Builder $providers): Builder
    {
        // PR #232 active-vendor visibility always applies, even when
        // the optional internal contract-review gate is disabled.
        $providers->publiclyDiscoverable();
        $fingerprints = ContractCounterpartyIdentity::configuredFingerprints();
        // A permanent source binding survives policy-off mode. This prevents
        // data from one operator becoming another operator's catalog.
        $providers->whereNotExists(function ($binding) use ($fingerprints) {
            $binding->selectRaw('1')->from('ocpi_partner_contract_reviews as source_binding')
                ->whereColumn('source_binding.provider_id', 'ocpi_providers.id')
                ->whereNotNull('source_binding.counterparty_hash');
            if ($fingerprints !== []) {
                $binding->where(function ($mismatch) use ($fingerprints) {
                    $mismatch->whereColumn('source_binding.connection_key', '!=', 'ocpi_providers.connection_key')
                        ->orWhereNotIn('source_binding.counterparty_hash', $fingerprints);
                });
            }
        });
        if (config('ocpi.contract_review_required') !== true) {
            return $providers;
        }

        if ($fingerprints === []) {
            return $providers->whereRaw('1 = 0');
        }
        $checkedAt = now('UTC');
        $sqlite = $providers->getModel()->getConnection()->getDriverName() === 'sqlite';

        return $providers->whereExists(function ($evidence) use ($checkedAt, $sqlite, $fingerprints) {
            $evidence->selectRaw('1')->from('ocpi_partner_contract_reviews as review')
                ->whereColumn('review.provider_id', 'ocpi_providers.id')
                ->whereColumn('review.owner_id', 'ocpi_providers.owner_id')
                ->whereColumn('review.connection_key', 'ocpi_providers.connection_key')
                ->where('review.decision', 'review')
                ->whereIn('review.counterparty_hash', $fingerprints)
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
        $identity = ContractCounterpartyIdentity::current($provider->connection_key);
        if (! $this->sourceBindingAllows($provider, $identity)) {
            return false;
        }
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

        return $identity !== null && $latest && $latest->counterparty_hash === $identity
            && (int) $latest->owner_id === (int) $owner->id
            && $latest->connection_key === $provider->connection_key
            && $latest->decision === 'review'
            && self::validUntil($latest->valid_until, now('UTC'));
    }
}
