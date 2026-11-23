<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persist bounded, non-identifying observations from the stored partner source.
 * These are per-provider freshness samples, not live-network coverage or SLAs.
 * capture() must run under the canonical provider lock in the ingest transaction.
 */
final class CatalogQualityHistory
{
    public const RETAIN_HOURS = 168;

    public const READ_LIMIT = 30;

    private const TABLE = 'ocpi_catalog_quality_history';

    private const COUNTS = [
        'published_count', 'within_policy_count', 'stale_count',
        'unknown_age_count', 'future_clock_count',
    ];

    public function capture(OcpiProvider $provider): void
    {
        $health = app(PartnerCatalogHealth::class)->snapshot($provider);
        $sampledAt = CarbonImmutable::now('UTC');
        $row = [
            'provider_id' => $provider->id,
            'owner_id' => $provider->owner_id,
            'observed_hour' => $sampledAt->startOfHour()->format('Y-m-d H:i:s.u'),
            'sampled_at' => $sampledAt->format('Y-m-d H:i:s.u'),
            'state' => $health['state'],
            'max_age_seconds' => $health['max_age_seconds'],
        ];
        foreach (self::COUNTS as $field) {
            $row[$field] = $health[$field];
        }
        DB::table(self::TABLE)->upsert(
            [$row], ['provider_id', 'owner_id', 'observed_hour'],
            ['sampled_at', 'state', 'max_age_seconds', ...self::COUNTS]
        );

        // Strict rolling seven-day clock horizon, independent of source
        // sync frequency; an idle provider cannot expose months-old samples.
        DB::table(self::TABLE)->where('provider_id', $provider->id)->where('owner_id', $provider->owner_id)
            ->where('observed_hour', '<', $sampledAt->subHours(self::RETAIN_HOURS)->format('Y-m-d H:i:s.u'))
            ->delete();
        // Retention is scoped to this provider and serialized by its ingest lock.
        $keep = DB::table(self::TABLE)->where('provider_id', $provider->id)->where('owner_id', $provider->owner_id)
            ->orderByDesc('observed_hour')->orderByDesc('id')
            ->limit(self::RETAIN_HOURS)->pluck('id');
        DB::table(self::TABLE)->where('provider_id', $provider->id)->where('owner_id', $provider->owner_id)
            ->whereNotIn('id', $keep)->delete();
    }

    /**
     * A history endpoint can hide records after seven days, but the stored
     * timestamps must also be deleted when a provider never syncs again.
     * Clean up only a bounded oldest-first batch: no provider, charger,
     * driver, account, external transport or source payload is touched.
     */
    public function purgeExpired(int $limit = 500): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Quality-history cleanup limit must be 1..1000.');
        }

        $before = CarbonImmutable::now('UTC')->subHours(self::RETAIN_HOURS)
            ->format('Y-m-d H:i:s.u');
        $ids = DB::table(self::TABLE)->where('observed_hour', '<', $before)
            ->orderBy('observed_hour')->orderBy('id')->limit($limit)->pluck('id');
        $deleted = $ids->isEmpty() ? 0 : DB::table(self::TABLE)->whereIn('id', $ids)
            ->where('observed_hour', '<', $before)->delete();

        return [
            'deleted' => $deleted,
            'considered' => $ids->count(),
            'limit' => $limit,
            'retention_hours' => self::RETAIN_HOURS,
            'evidence' => 'STORED_PARTNER_CATALOG_TIMESTAMPS_ONLY',
            'external_requests' => 0,
            'live_availability_verified' => false,
        ];
    }

    public function read(User $snapshot, OcpiProvider $provider): array
    {
        return DB::transaction(function () use ($snapshot, $provider) {
            $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->account_status === 'active'
                && in_array($actor->role, ['admin', 'vendor'], true), 403);
            abort_unless($actor->role === $snapshot->role
                && (int) $actor->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $actor->password, (string) $snapshot->password),
                409, 'Account authorization changed. Sign in again.');
            $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->role === 'admin' || $current->owner_id === $actor->id, 404);

            $rows = DB::table(self::TABLE)->where('provider_id', $current->id)
                ->where('owner_id', $current->owner_id)
                ->where('observed_hour', '>=', CarbonImmutable::now('UTC')
                    ->subHours(self::RETAIN_HOURS)->format('Y-m-d H:i:s.u'))
                ->orderByDesc('observed_hour')->orderByDesc('id')
                ->limit(self::READ_LIMIT)->get([
                    'observed_hour', 'sampled_at', 'state', 'max_age_seconds',
                    ...self::COUNTS,
                ]);
            $samples = [];
            foreach ($rows as $row) {
                $hour = CarbonImmutable::parse($row->observed_hour, 'UTC');
                $sampled = CarbonImmutable::parse($row->sampled_at, 'UTC');
                abort_unless($hour->equalTo($hour->startOfHour())
                    && $sampled->greaterThanOrEqualTo($hour)
                    && $sampled->lessThan($hour->addHour()), 409,
                    'Stored quality observation clock requires reconciliation.');
                $numbers = array_map(static fn ($key) => $row->{$key}, self::COUNTS);
                $hasCounts = in_array($row->state, ['within_policy', 'attention', 'empty'], true);
                if ($hasCounts) {
                    abort_unless($row->max_age_seconds >= 60 && $row->max_age_seconds <= 604800
                        && count(array_filter($numbers, static fn ($value) => $value === null)) === 0
                        && array_reduce($numbers, static fn ($ok, $v) => $ok && is_numeric($v)
                            && (int) $v >= 0 && (int) $v <= 1000000, true)
                        && (int) $row->within_policy_count + (int) $row->stale_count + (int) $row->unknown_age_count === (int) $row->published_count
                        && (int) $row->future_clock_count <= (int) $row->unknown_age_count
                        && $row->state === ((int) $row->published_count === 0 ? 'empty'
                            : (((int) $row->stale_count + (int) $row->unknown_age_count) > 0 ? 'attention' : 'within_policy')),
                        409, 'Stored quality observation requires reconciliation.');
                } else {
                    abort_unless(in_array($row->state, ['unavailable', 'policy_unavailable'], true)
                        && array_filter($numbers, static fn ($value) => $value !== null) === [],
                        409, 'Stored quality observation requires reconciliation.');
                }

                $sample = [
                    'observed_hour' => $hour->toISOString(),
                    'sampled_at' => $sampled->toISOString(),
                    'state' => $row->state,
                    'max_age_seconds' => $row->max_age_seconds === null ? null : (int) $row->max_age_seconds,
                ];
                foreach (self::COUNTS as $field) {
                    $sample[$field] = $row->{$field} === null ? null : (int) $row->{$field};
                }
                $samples[] = $sample;
            }

            return [
                'scope' => 'stored_partner_catalog_quality_history',
                'provider' => ['id' => $current->id, 'name' => $current->name,
                    'authorized' => (bool) $current->authorized],
                'period' => 'hour_utc',
                'sample_limit' => self::READ_LIMIT,
                'retention_hours' => self::RETAIN_HOURS,
                'count' => count($samples),
                'samples' => $samples,
                'evidence' => 'STORED_PARTNER_CATALOG_TIMESTAMPS_ONLY',
                'sample_completeness' => 'STORED_SYNC_AND_OPTIONAL_PERIODIC_SNAPSHOTS',
                'source_covers_whole_network' => false,
                'live_availability_verified' => false,
                'physical_charging_verified' => false,
                'remote_control' => false,
                'billing_authority' => false,
            ];
        }, 3);
    }
}
