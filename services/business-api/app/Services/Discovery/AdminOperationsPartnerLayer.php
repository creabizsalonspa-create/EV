<?php

namespace App\Services\Discovery;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read only the published, authorized partner locations already in the catalog. */
final class AdminOperationsPartnerLayer
{
    public function __construct(private DiscoveryViewport $viewport) {}

    public function page(array $filters, CarbonInterface $checkedAt): array
    {
        $providers = OcpiProvider::publiclyDiscoverable();
        $query = OcpiRecord::query()->where('module', 'locations')->where('normalized->publish', true)
            ->whereIn('ocpi_provider_id', (clone $providers)->select('id'));
        $this->viewport->applyToPartner($query, (float) ($filters['south'] ?? -90), (float) ($filters['north'] ?? 90),
            (float) ($filters['west'] ?? -180), (float) ($filters['east'] ?? 180));
        if (isset($filters['country'])) {
            $query->where('normalized->country', $filters['country']);
        }
        $name = PartnerCatalogText::field('name');
        $text = trim($filters['q'] ?? '');
        if ($text !== '') {
            $comparison = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
            $query->where(fn (Builder $match) => $match->whereRaw("$name $comparison ? ESCAPE '!'", [$pattern])
                ->orWhereIn('ocpi_provider_id', (clone $providers)
                    ->whereRaw("ocpi_providers.name $comparison ? ESCAPE '!'", [$pattern])->select('id')));
        }

        [$latitude, $longitude] = $this->viewport->partnerCoordinates();

        return $query->select(['ocpi_records.id', 'ocpi_records.source_updated_at', 'ocpi_records.received_at',
            'ocpi_records.normalized->country as partner_country'])
            ->selectRaw("$name AS partner_name, $latitude AS latitude, $longitude AS longitude")
            ->addSelect(['provider_label' => $providers->select('name')->whereColumn('ocpi_providers.id', 'ocpi_records.ocpi_provider_id')])
            ->orderByDesc('ocpi_records.id')->paginate(25)
            ->through(function (OcpiRecord $row) use ($checkedAt) {
                $age = PartnerCatalogAge::present($row, $checkedAt);

                return [
                    'id' => $row->id,
                    'name' => $row->partner_name,
                    'country' => is_string($row->partner_country) && preg_match('/^[A-Z]{3}$/D', $row->partner_country) ? $row->partner_country : null,
                    'latitude' => (float) $row->latitude,
                    'longitude' => (float) $row->longitude,
                    'provider_label' => $row->provider_label,
                    'source' => 'partner_catalog',
                    'source_clock_evidence' => $age['source_clock_evidence'],
                    'reported_source_age_minutes' => $age['reported_source_age_minutes'],
                    'availability' => 'unknown',
                    'physical_availability_verified' => false,
                    'remote_control' => false,
                ];
            })->toArray();
    }
}
