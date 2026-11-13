<?php

namespace App\Services\Discovery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CatalogTextFilters
{
    /** Filter before pagination; operator/access labels confer no charging authority. */
    public function apply(Builder $query, array $filters): void
    {
        $operator = match (DB::connection()->getDriverName()) {
            'pgsql' => 'ILIKE',
            'sqlite' => 'LIKE',
            default => throw new \LogicException('Unsupported station search database.'),
        };
        foreach (['q', 'operator', 'access', 'address'] as $key) {
            $text = trim($filters[$key] ?? '');
            if ($text === '') {
                continue;
            }
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
            if ($key === 'q') {
                $query->whereRaw("stations.name $operator ? ESCAPE '!'", [$pattern]);
            } elseif ($key === 'operator') {
                $query->whereExists(fn ($q) => $q->selectRaw('1')->from('station_information as info')
                    ->whereColumn('info.station_id', 'stations.id')
                    ->whereRaw("info.operator_name $operator ? ESCAPE '!'", [$pattern]));
            } elseif ($key === 'address') {
                $query->whereExists(fn ($q) => $q->selectRaw('1')->from('station_information as info')
                    ->whereColumn('info.station_id', 'stations.id')
                    ->where(fn ($match) => $match
                        ->whereRaw("info.address_en $operator ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("info.address_ar $operator ? ESCAPE '!'", [$pattern])));
            } else {
                $query->whereExists(fn ($q) => $q->selectRaw('1')->from('station_information as info')
                    ->whereColumn('info.station_id', 'stations.id')
                    ->where(fn ($match) => $match
                        ->whereRaw("info.access_en $operator ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("info.access_ar $operator ? ESCAPE '!'", [$pattern])));
            }
        }
    }
}
