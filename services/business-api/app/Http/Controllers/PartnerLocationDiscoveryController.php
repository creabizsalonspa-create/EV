<?php

namespace App\Http\Controllers;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Services\Discovery\PartnerCatalogGeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PartnerLocationDiscoveryController extends Controller
{
    /**
     * The same canonical OCPI records as the existing roaming catalog.
     * Coordinates and ages never imply live availability or control rights.
     */
    public function index(Request $request, PartnerCatalogGeo $geo)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:80',
            'country' => 'nullable|string|regex:/^[A-Z]{3}$/D',
            'connector_standard' => 'nullable|string|max:64',
            'provider' => 'nullable|string|max:80',
            'latitude' => 'required_with:longitude,radius_km|numeric|between:-90,90',
            'longitude' => 'required_with:latitude,radius_km|numeric|between:-180,180',
            'radius_km' => 'required_with:latitude,longitude|numeric|between:1,200',
        ]);
        $text = trim($filters['q'] ?? '');
        $providerText = trim($filters['provider'] ?? '');
        $authorizedProviders = OcpiProvider::query()->where('authorized', true);
        if ($providerText !== '') {
            $driver = DB::connection()->getDriverName();
            if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
                throw new \LogicException('Unsupported partner provider-search database.');
            }
            $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $providerText).'%';
            $authorizedProviders->whereRaw("ocpi_providers.name $operator ? ESCAPE '!'", [$pattern]);
        }

        $query = OcpiRecord::query()
            ->where('module', 'locations')
            ->where('normalized->publish', true)
            ->whereIn('ocpi_provider_id', $authorizedProviders->select('id'))
            ->when($text !== '', function ($query) use ($text) {
                // SQL LIKE wildcards are not the user's search text. Both supported
                // databases accept an explicit ! escape; every value is bound.
                $driver = DB::connection()->getDriverName();
                if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
                    throw new \LogicException('Unsupported partner text-search database.');
                }
                $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
                $query->where(function ($match) use ($driver, $operator, $pattern) {
                    foreach (['name', 'city', 'address'] as $field) {
                        $column = $driver === 'pgsql'
                            ? "(ocpi_records.normalized->>'".$field."')"
                            : "json_extract(ocpi_records.normalized, '$.".$field."')";
                        $match->orWhereRaw("$column $operator ? ESCAPE '!'", [$pattern]);
                    }
                });
            })
            ->when(! empty($filters['country']), fn ($q) => $q->where('normalized->country', $filters['country']))
            ->when(! empty($filters['connector_standard']), fn ($q) => $q->whereJsonContains('normalized->connector_standards', $filters['connector_standard']));

        $nearby = isset($filters['latitude']);
        if ($nearby) {
            $geo->within($query, (float) $filters['latitude'], (float) $filters['longitude'], (float) $filters['radius_km']);
        } else {
            $query->select('ocpi_records.*');
        }
        // Name is provider-configured and not proof of a legal network operator.
        // The same authorization gate prevents exposing a revoked provider label.
        $query->addSelect([
            'provider_label' => OcpiProvider::query()->select('name')
                ->whereColumn('ocpi_providers.id', 'ocpi_records.ocpi_provider_id')
                ->where('authorized', true)->limit(1),
        ]);

        // The final ID key also stabilizes ties in distance.
        $now = now()->getTimestamp();

        return $query->orderByDesc('ocpi_records.id')->paginate(25)->appends($filters)
            ->through(function (OcpiRecord $record) use ($nearby, $now) {
                $age = $record->source_updated_at
                    ? max(0, $now - $record->source_updated_at->getTimestamp())
                    : null;

                return [
                    'id' => $record->id,
                    'provider_id' => $record->ocpi_provider_id,
                    'provider_label' => $record->provider_label,
                    'provider_label_evidence' => 'PROVIDER_CONFIGURED_UNVERIFIED',
                    'location' => $record->normalized,
                    'received_at' => $record->received_at,
                    'source_updated_at' => $record->source_updated_at,
                    'reported_source_age_minutes' => $age === null ? null : intdiv($age, 60),
                    'source_timestamp_evidence' => 'PARTNER_REPORTED_ONLY',
                    'evidence' => 'PARTNER_REPORTED_UNVERIFIED',
                    'availability' => 'unknown',
                    'remote_control' => false,
                    'reservable' => false,
                    ...($nearby ? [
                        'distance_km' => round((float) $record->distance_km, 3),
                        'distance_basis' => 'great_circle',
                    ] : []),
                ];
            });
    }
}
