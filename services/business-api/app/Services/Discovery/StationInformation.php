<?php

namespace App\Services\Discovery;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StationInformation
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function forStation(int $stationId): array
    {
        return $this->represent(DB::table('station_information')->where('station_id', $stationId)->first());
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
            'weekly_hours' => json_decode($row->weekly_hours, true, 512, JSON_THROW_ON_ERROR),
            'closed_dates' => json_decode($row->closed_dates, true, 512, JSON_THROW_ON_ERROR),
            'amenities' => json_decode($row->amenities, true, 512, JSON_THROW_ON_ERROR),
            'accessibility' => json_decode($row->accessibility, true, 512, JSON_THROW_ON_ERROR),
            'source_label' => 'VENDOR_REPORTED_UNVERIFIED',
            'updated_at' => $row->updated_at,
        ];
    }
}
