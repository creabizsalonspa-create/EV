<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Opt-in hourly observations over already stored partner locations.
 * Reads no credential, driver, payment or remote network source and performs
 * no OCPI GET, reservation, charging command or external API request.
 */
final class CatalogQualitySampler
{
    public const MAX_PROVIDERS = 50;

    public function run(int $limit = 25): array
    {
        if (config('ocpi.catalog_quality_sampling_enabled') !== true) {
            return ['state' => 'disabled', 'attempted' => 0,
                'sampled' => 0, 'skipped' => 0, 'failed' => 0,
                'external_requests' => 0];
        }
        if ($limit < 1 || $limit > self::MAX_PROVIDERS) {
            throw new \InvalidArgumentException('Quality sampling limit must be 1..50.');
        }

        $hour = CarbonImmutable::now('UTC')->startOfHour()->format('Y-m-d H:i:s.u');
        // Only select providers with stored location observations and without
        // an hourly sample for the current owner. A large fleet drains across
        // successive scheduled invocations without starving high-ID providers.
        $candidates = OcpiProvider::publiclyDiscoverable()
            ->where('version', '2.2.1')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('ocpi_records as r')
                ->whereColumn('r.ocpi_provider_id', 'ocpi_providers.id')
                ->where('r.module', 'locations'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('ocpi_catalog_quality_history as h')
                ->whereColumn('h.provider_id', 'ocpi_providers.id')
                ->whereColumn('h.owner_id', 'ocpi_providers.owner_id')
                ->where('h.observed_hour', $hour))
            ->orderBy('id')->limit($limit)->pluck('id');
        $sampled = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($candidates as $id) {
            try {
                $captured = DB::transaction(function () use ($id, $hour) {
                    $initial = OcpiProvider::find($id);
                    if (! $initial) {
                        return false;
                    }
                    // Match the account-then-provider lock ordering of managed
                    // provider operations and revalidate after both locks.
                    $owner = User::whereKey($initial->owner_id)->lockForUpdate()->first();
                    $current = OcpiProvider::whereKey($id)->lockForUpdate()->first();
                    if (! $current || ! $owner || $current->owner_id !== $owner->id
                        || $owner->role !== 'vendor' || $owner->account_status !== 'active'
                        || ! $current->authorized || $current->version !== '2.2.1'
                        || ! DB::table('ocpi_records')->where('ocpi_provider_id', $id)
                            ->where('module', 'locations')->exists()
                        || DB::table('ocpi_catalog_quality_history')->where('provider_id', $id)
                            ->where('owner_id', $owner->id)->where('observed_hour', $hour)->exists()) {
                        return false;
                    }
                    app(CatalogQualityHistory::class)->capture($current);

                    return true;
                }, 3);
                $captured ? $sampled++ : $skipped++;
            } catch (Throwable) {
                // A provider failure cannot discard an independent approved
                // provider's observation; report counts only, never secrets.
                $failed++;
            }
        }

        return ['state' => $failed ? 'requires_review' : 'sampled',
            'attempted' => count($candidates), 'sampled' => $sampled,
            'skipped' => $skipped, 'failed' => $failed, 'external_requests' => 0,
            'evidence' => 'STORED_PARTNER_CATALOG_TIMESTAMPS_ONLY',
            'live_availability_verified' => false, 'physical_charging_verified' => false];
    }
}
