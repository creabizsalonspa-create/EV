<?php

namespace App\Services\Discovery;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StationInformation
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function forStation(int $stationId): array
    {
        // Bind the standalone GET/PUT reply to its requested station. The
        // vendor editor must not interpret a wrong-station or stale response
        // as authoritative metadata for its current selection.
        return ['station_id' => $stationId] + $this->represent(
            DB::table('station_information')->where('station_id', $stationId)->first()
        );
    }

    public function forStations(Collection $stationIds): Collection
    {
        if ($stationIds->isEmpty()) {
            return collect();
        }

        return DB::table('station_information')->whereIn('station_id', $stationIds)->get()
            ->mapWithKeys(fn ($row) => [$row->station_id => $this->represent($row)]);
    }

    public function represent(?object $row): array
    {
        if (! $row) {
            return [
                'revision' => 0,
                'description_en' => null, 'description_ar' => null,
                'access_en' => null, 'access_ar' => null,
                'notice_en' => null, 'notice_ar' => null, 'notice_until' => null,
                'notice_active' => false,
                'weekly_hours' => array_fill_keys(self::DAYS, ['open' => null, 'close' => null]),
                'closed_dates' => [], 'amenities' => [], 'accessibility' => [],
                'source_label' => 'NOT_PROVIDED',
                'updated_at' => null,
            ];
        }

        return [
            'revision' => (int) $row->revision,
            'description_en' => $row->description_en,
            'description_ar' => $row->description_ar,
            'access_en' => $row->access_en,
            'access_ar' => $row->access_ar,
            'notice_en' => $row->notice_en,
            'notice_ar' => $row->notice_ar,
            'notice_until' => $row->notice_until,
            // Expiry is inclusive through the calendar date in UTC. Client clock
            // and the station's possibly unknown timezone are not trusted.
            'notice_active' => $row->notice_until !== null
                && (trim((string) $row->notice_en) !== '' || trim((string) $row->notice_ar) !== '')
                && \Carbon\CarbonImmutable::parse($row->notice_until.' 23:59:59', 'UTC')->gte(now('UTC')),
            'weekly_hours' => json_decode($row->weekly_hours, true, 512, JSON_THROW_ON_ERROR),
            'closed_dates' => json_decode($row->closed_dates, true, 512, JSON_THROW_ON_ERROR),
            'amenities' => json_decode($row->amenities, true, 512, JSON_THROW_ON_ERROR),
            'accessibility' => json_decode($row->accessibility, true, 512, JSON_THROW_ON_ERROR),
            'source_label' => 'VENDOR_REPORTED_UNVERIFIED',
            'updated_at' => $row->updated_at,
        ];
    }
}
